<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Binance.php';
require_once __DIR__ . '/Exchange.php';
require_once __DIR__ . '/Risk.php';
require_once __DIR__ . '/EngineOrders.php';

/**
 * Pure market making (docs/DESIGN-ENGINES.md §8).
 *
 * Quote one bid and one ask around the mid, refresh them when they age or drift,
 * and skew the two sizes with the inventory so the book position mean-reverts to
 * `pmm_target_base_pct`.
 *
 * HONEST ECONOMICS, repeated here because it decides whether running this is
 * sensible: at VIP0 the maker fee equals the taker fee (0.1 %), so a round trip
 * costs ~0.2 % while observed spreads on the majors are 0.01-0.05 %. **pmm is
 * expected to LOSE money at VIP0 fees.** It becomes viable at better fee tiers,
 * on wide-spread pairs, or with maker rebates. The panel says the same.
 *
 * Order discipline: at most one new order per side per tick, never more than
 * min(engine_max_orders, MAX_NUM_ORDERS − 2) live orders, a bid strictly below
 * the book bid and an ask strictly above the book ask (so a post-only quote can
 * never take), and every price checked against PERCENT_PRICE_BY_SIDE.
 *
 * tick() is called by Bot AFTER EngineOrders::sync(); it never syncs by itself.
 */
final class EnginePmm
{
    /** Live orders kept free below the exchange's MAX_NUM_ORDERS. */
    const ORDER_HEADROOM = 2;
    /** Neither side may ever exceed pmm_order_usdt × this. */
    const SKEW_MAX_FACTOR = 1.5;
    /** Growth applied to the opposite side at full skew (bounded by SKEW_MAX_FACTOR). */
    const SKEW_GROW = 0.5;
    /** Quantities/notionals below this are treated as zero. */
    const EPS = 1.0e-12;

    /** @var array */
    private $cfg;
    /** @var Db */
    private $db;
    /** @var ExchangeInterface */
    private $ex;
    /** @var EngineOrders */
    private $orders;
    /** @var array parsed symbol info (flat shape) for $symbol */
    private $info;
    /** @var string */
    private $symbol;
    /** @var string */
    private $mode;
    /** @var string */
    private $tickSize;
    /** @var string */
    private $stepSize;
    /** @var float */
    private $minQty;
    /** @var float */
    private $minNotional;
    /** @var int */
    private $maxNumOrders;

    public function __construct(array $cfg, Db $db, ExchangeInterface $ex, EngineOrders $orders, array $info)
    {
        $this->cfg    = $cfg;
        $this->db     = $db;
        $this->ex     = $ex;
        $this->orders = $orders;
        $this->symbol = self::symbolOf($cfg);
        $this->mode   = self::modeOf($cfg);
        $this->info   = self::flatInfo($info, $this->symbol);

        $this->tickSize     = isset($this->info['tickSize']) && is_string($this->info['tickSize']) && $this->info['tickSize'] !== ''
            ? $this->info['tickSize'] : '0.00000001';
        $this->stepSize     = isset($this->info['stepSize']) && is_string($this->info['stepSize']) && $this->info['stepSize'] !== ''
            ? $this->info['stepSize'] : '0.00000001';
        $this->minQty       = isset($this->info['minQty']) && is_numeric($this->info['minQty']) ? (float) $this->info['minQty'] : 0.0;
        $this->minNotional  = isset($this->info['minNotional']) && is_numeric($this->info['minNotional']) ? (float) $this->info['minNotional'] : 5.0;
        $this->maxNumOrders = isset($this->info['maxNumOrders']) && is_numeric($this->info['maxNumOrders']) ? (int) $this->info['maxNumOrders'] : 200;
    }

    /**
     * One market-making tick, run after EngineOrders::sync().
     * @return array{action:string, detail:string}
     */
    public function tick(float $bid, float $ask, float $baseFree, float $quoteFree): array
    {
        if (!is_finite($bid) || !is_finite($ask) || $bid <= 0 || $ask <= 0 || $ask < $bid) {
            return self::res('skipped', 'no book for ' . $this->symbol);
        }
        if (!is_finite($baseFree) || $baseFree < 0) {
            $baseFree = 0.0;
        }
        if (!is_finite($quoteFree) || $quoteFree < 0) {
            $quoteFree = 0.0;
        }

        $mid    = ($bid + $ask) / 2.0;
        $spread = $this->pct('pmm_spread_pct', 0.25);

        $bidPriceStr = Util::roundToTick($mid * (1.0 - $spread / 100.0), $this->tickSize, 'down');
        $askPriceStr = Util::roundToTick($mid * (1.0 + $spread / 100.0), $this->tickSize, 'up');
        $bidPrice    = (float) $bidPriceStr;
        $askPrice    = (float) $askPriceStr;

        // 1. refresh: drop quotes that aged out or drifted off their intended level
        $refresh = $this->refresh($bidPrice, $askPrice, $spread);
        $baseFree  += $refresh['base_released'];
        $quoteFree += $refresh['quote_released'];
        $liveCount  = $refresh['live'];
        $hasBid     = $refresh['has_bid'];
        $hasAsk     = $refresh['has_ask'];
        $notes      = [];

        // 2. inventory position
        $baseValue = $baseFree * $bid;
        $denom     = $baseValue + $quoteFree;
        $basePct   = $denom > self::EPS ? $baseValue / $denom * 100.0 : 0.0;

        // 5. inventory skew (applied to both sizes before either is posted)
        $target  = Util::clamp($this->f('pmm_target_base_pct', 50.0), 0.0, 100.0);
        $maxBase = Util::clamp($this->f('pmm_max_base_pct', 80.0), 1.0, 100.0);
        if ($maxBase <= $target) {
            $maxBase = min(100.0, $target + 1.0);
        }
        $base  = $this->f('pmm_order_usdt', Risk::ENGINE_ORDER_USDT_DEFAULT);
        $sizes = self::skew($basePct, $base, $target, $maxBase);
        $cap   = $this->orderCap();

        // 3. bid
        $placedBid = '';
        if ($hasBid) {
            $notes[] = 'bid live';
        } elseif ($basePct >= $maxBase) {
            $notes[] = 'inventory ' . self::pctStr($basePct) . '% >= max, not bidding';
        } elseif ($liveCount >= $cap) {
            $notes[] = 'order cap ' . $cap . ' reached';
        } else {
            $placedBid = $this->postBid($bidPrice, $bidPriceStr, $bid, $mid, $sizes[0], $quoteFree, $notes);
            if ($placedBid !== '') {
                $liveCount++;
            }
        }

        // 4. ask
        $placedAsk = '';
        if ($hasAsk) {
            $notes[] = 'ask live';
        } elseif ($basePct <= 100.0 - $maxBase) {
            $notes[] = 'inventory ' . self::pctStr($basePct) . '% <= min, not asking';
        } elseif ($liveCount >= $cap) {
            $notes[] = 'order cap ' . $cap . ' reached';
        } else {
            $placedAsk = $this->postAsk($askPrice, $askPriceStr, $ask, $mid, $sizes[1], $baseFree, $notes);
            if ($placedAsk !== '') {
                $liveCount++;
            }
        }

        $parts = [];
        if ($placedBid !== '') {
            $parts[] = $placedBid;
        }
        if ($placedAsk !== '') {
            $parts[] = $placedAsk;
        }
        $detail = 'mid=' . Util::trimZeros(Util::roundToTick($mid, $this->tickSize, 'nearest'))
            . ' spread=' . self::pctStr($spread) . '% base=' . self::pctStr($basePct) . '%'
            . ' live=' . $liveCount . '/' . $cap;
        if ($refresh['cancelled'] > 0) {
            $detail .= ' refreshed=' . $refresh['cancelled'];
        }
        if ($parts !== []) {
            $detail = implode('; ', $parts) . ' | ' . $detail;
        }
        if ($notes !== []) {
            $detail .= ' | ' . implode(', ', array_slice($notes, 0, 4));
        }

        if ($parts !== []) {
            return self::res('quote', $detail);
        }
        if ($refresh['cancelled'] > 0) {
            return self::res('refresh', $detail);
        }
        return self::res('idle', $detail);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Cancel every live quote older than pmm_refresh_sec or whose price has drifted
     * more than pmm_spread_pct/2 away from the level it should be quoting now.
     *
     * @return array{cancelled:int, live:int, has_bid:bool, has_ask:bool, base_released:float, quote_released:float}
     */
    private function refresh(float $bidPrice, float $askPrice, float $spread): array
    {
        $maxAge = max(1, $this->int('pmm_refresh_sec', 60));
        $drift  = $spread / 2.0;
        $now    = time();

        $out = ['cancelled' => 0, 'live' => 0, 'has_bid' => false, 'has_ask' => false,
                'base_released' => 0.0, 'quote_released' => 0.0];

        foreach ($this->db->openEngineOrders($this->symbol, $this->mode) as $o) {
            $isSell   = strtoupper((string) ($o['side'] ?? '')) === 'SELL';
            $price    = isset($o['price']) && is_numeric($o['price']) ? (float) $o['price'] : 0.0;
            $qty      = isset($o['qty']) && is_numeric($o['qty']) ? (float) $o['qty'] : 0.0;
            $filled   = isset($o['filled_qty']) && is_numeric($o['filled_qty']) ? (float) $o['filled_qty'] : 0.0;
            $clientId = (string) ($o['client_id'] ?? '');
            $ts       = isset($o['created_at']) ? Util::isoToTs((string) $o['created_at']) : null;
            $age      = $ts === null ? 0 : $now - $ts;

            $want = $isSell ? $askPrice : $bidPrice;
            $off  = ($want > 0 && $price > 0) ? abs($price - $want) / $want * 100.0 : 0.0;
            $stale = ($age >= $maxAge) || ($drift > 0 && $off > $drift);

            if ($stale && $clientId !== '') {
                $ok = false;
                try {
                    $ok = $this->orders->cancel($clientId);
                } catch (Throwable $e) {
                    Log::warn('pmm: cancel failed', ['symbol' => $this->symbol, 'client_id' => $clientId, 'error' => $e->getMessage()]);
                }
                if ($ok) {
                    // cancel() is also true when the quote FILLED between sync() and this call
                    // (-2011 resolves to FILLED): only what is still unfilled comes back free
                    $after     = $this->db->engineOrder($clientId);
                    $filledNow = ($after !== null && isset($after['filled_qty']) && is_numeric($after['filled_qty']))
                        ? (float) $after['filled_qty'] : $filled;
                    $released  = max(0.0, $qty - max($filled, $filledNow));
                    $out['cancelled']++;
                    if ($isSell) {
                        $out['base_released'] += $released;
                    } else {
                        $out['quote_released'] += $released * $price;
                    }
                    continue;
                }
            }
            $out['live']++;
            if ($isSell) {
                $out['has_ask'] = true;
            } else {
                $out['has_bid'] = true;
            }
        }
        return $out;
    }

    /** @return string '' when nothing was posted, else a short description */
    private function postBid(float $price, string $priceStr, float $bookBid, float $mid, float $quote, float $quoteFree, array &$notes): string
    {
        if ($price <= 0) {
            return '';
        }
        if ($price >= $bookBid) {
            // post-only safety: a buy at or above the bid would take, not make
            $notes[] = 'bid not below book bid';
            return '';
        }
        if (!Binance::priceAllowed($price, 'BUY', $mid, $this->info)) {
            $notes[] = 'bid outside PERCENT_PRICE_BY_SIDE';
            return '';
        }
        if ($quote > $quoteFree) {
            $quote = $quoteFree;
        }
        $qty = (float) Util::floorToStep($quote / $price, $this->stepSize);
        if ($quote <= self::EPS || $qty <= self::EPS || $qty < $this->minQty || $qty * $price < $this->minNotional - 1e-9) {
            $notes[] = 'quote free short for a bid';
            return '';
        }
        $res = $this->tryPlace('BUY', $price, $quote, 'pmm_bid');
        return $res === null ? '' : 'bid ' . Util::money($quote, 2) . ' @' . Util::trimZeros($priceStr);
    }

    /** @return string '' when nothing was posted, else a short description */
    private function postAsk(float $price, string $priceStr, float $bookAsk, float $mid, float $quote, float $baseFree, array &$notes): string
    {
        if ($price <= 0) {
            return '';
        }
        if ($price <= $bookAsk) {
            // post-only safety: a sell at or below the ask would take, not make
            $notes[] = 'ask not above book ask';
            return '';
        }
        if (!Binance::priceAllowed($price, 'SELL', $mid, $this->info)) {
            $notes[] = 'ask outside PERCENT_PRICE_BY_SIDE';
            return '';
        }
        // the ask is sized from the inventory: never more base than is actually free
        $wanted = $quote / $price;
        $qtyStr = Util::floorToStep(min($wanted, $baseFree), $this->stepSize);
        $qty    = (float) $qtyStr;
        if ($qty <= self::EPS || $qty < $this->minQty || $qty * $price < $this->minNotional - 1e-9) {
            $notes[] = 'inventory below the filters, not asking';
            return '';
        }
        // pad by a hair so EngineOrders' quote/price division floors back to exactly $qty
        $res = $this->tryPlace('SELL', $price, $qty * $price * (1.0 + 1e-9), 'pmm_ask');
        return $res === null ? '' : 'ask ' . Util::trimZeros($qtyStr) . ' @' . Util::trimZeros($priceStr);
    }

    /**
     * EngineOrders::place() guarded. A BinanceException is re-thrown: the Bot error
     * policy (-2015, 429/418, -1013, network errors) owns those. Anything else costs
     * this quote, never the tick.
     */
    private function tryPlace(string $side, float $price, float $quote, string $purpose): ?array
    {
        try {
            return $this->orders->place($side, $price, $quote, $purpose, null);
        } catch (BinanceException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warn('pmm: order rejected', [
                'symbol' => $this->symbol, 'side' => $side, 'purpose' => $purpose,
                'price' => $price, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Inventory skew (§8.5): above the target the bid shrinks and the ask grows,
     * linearly between target and max; below the target the mirror image. Neither
     * side ever exceeds pmm_order_usdt × 1.5.
     * @return array{0:float, 1:float} [bid quote, ask quote]
     */
    private static function skew(float $basePct, float $base, float $target, float $maxBase): array
    {
        if ($base <= 0) {
            return [0.0, 0.0];
        }
        $capHi = $base * self::SKEW_MAX_FACTOR;
        if ($basePct > $target) {
            $span = max($maxBase - $target, 1e-9);
            $f    = Util::clamp(($basePct - $target) / $span, 0.0, 1.0);
            return [$base * (1.0 - $f), min($capHi, $base * (1.0 + self::SKEW_GROW * $f))];
        }
        $span = max($target - (100.0 - $maxBase), 1e-9);
        $g    = Util::clamp(($target - $basePct) / $span, 0.0, 1.0);
        return [min($capHi, $base * (1.0 + self::SKEW_GROW * $g)), $base * (1.0 - $g)];
    }

    /** min(engine_max_orders, MAX_NUM_ORDERS − 2), never below 1. */
    private function orderCap(): int
    {
        $cfgCap = (int) Util::clamp((float) $this->int('engine_max_orders', 12), 1.0, (float) Risk::ENGINE_MAX_ORDERS_CAP);
        $exCap  = $this->maxNumOrders > 0 ? $this->maxNumOrders - self::ORDER_HEADROOM : $cfgCap;
        return (int) max(1, min($cfgCap, $exCap));
    }

    /* --------------------------------------------------------------- helpers */

    private static function res(string $action, string $detail): array
    {
        return ['action' => $action, 'detail' => $detail];
    }

    private static function pctStr(float $v): string
    {
        return Util::trimZeros(sprintf('%.2F', $v));
    }

    /** Accepts either a flat symbol-info array or a map keyed by symbol. */
    private static function flatInfo(array $info, string $symbol): array
    {
        if (isset($info[$symbol]) && is_array($info[$symbol])) {
            return $info[$symbol];
        }
        if (isset($info['tickSize']) || isset($info['stepSize'])) {
            return $info;
        }
        if (count($info) === 1) {
            foreach ($info as $v) {
                if (is_array($v) && (isset($v['tickSize']) || isset($v['stepSize']))) {
                    return $v;
                }
            }
        }
        return [];
    }

    private static function symbolOf(array $cfg): string
    {
        $s = strtoupper(trim((string) ($cfg['engine_symbol'] ?? '')));
        return $s === '' ? 'DOGEUSDT' : $s;
    }

    private static function modeOf(array $cfg): string
    {
        $m = strtolower(trim((string) ($cfg['mode'] ?? 'paper')));
        return in_array($m, ['paper', 'demo', 'testnet', 'live'], true) ? $m : 'paper';
    }

    private function f(string $key, float $default): float
    {
        $v = isset($this->cfg[$key]) && is_numeric($this->cfg[$key]) ? (float) $this->cfg[$key] : $default;
        return is_finite($v) ? $v : $default;
    }

    /** A percentage config value; non-positive and non-finite values fall back to the default. */
    private function pct(string $key, float $default): float
    {
        $v = $this->f($key, $default);
        return $v > 0 ? $v : $default;
    }

    private function int(string $key, int $default): int
    {
        return isset($this->cfg[$key]) && is_numeric($this->cfg[$key]) ? (int) $this->cfg[$key] : $default;
    }
}
