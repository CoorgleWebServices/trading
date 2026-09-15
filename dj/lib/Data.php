<?php

declare(strict_types=1);

/**
 * dj/lib/Data.php — the constant tables of the 9Bar desk.
 *
 * Every list here is copied from the published artifact (9Bar Desk) verbatim: the
 * titles AND the explanatory body text. The writing is the value of these
 * checklists, so nothing in this file may be paraphrased, trimmed or reworded.
 *
 * Shape notes (deliberately the artifact's own key names):
 *   step   = ['id' => string, 't' => title, 'd' => explanatory body]
 *   phase  = ['phase' => string, 'steps' => step[]]
 *   rung   = ['t' => title, 'd' => body]            (LADDER)
 *   rig    = ['t' => title, 'd' => body, 'price' => int]  (RIG, price in USD, 0 = free)
 *
 * Self-contained: this file requires nothing.
 */
final class Data
{
    /** Crate buckets: id, human label, and the CSS class that tints the tag. */
    const BUCKETS = [
        ['id' => 'bollywood', 'label' => 'Bollywood', 'cls' => 'b-bollywood'],
        ['id' => 'desi', 'label' => 'Desi bass', 'cls' => 'b-desi'],
        ['id' => 'house', 'label' => 'House', 'cls' => 'b-house'],
        ['id' => 'techno', 'label' => 'Techno', 'cls' => 'b-techno'],
        ['id' => 'edit', 'label' => 'Edit / mashup', 'cls' => 'b-desi'],
        ['id' => 'tool', 'label' => 'Acapella / tool', 'cls' => 'b-techno'],
    ];

    /** Prep states a track can be in, in ascending order of readiness. */
    const PREP = [
        ['id' => 'raw', 'label' => 'Raw'],
        ['id' => 'gridded', 'label' => 'Gridded'],
        ['id' => 'ready', 'label' => 'Cued + ready'],
    ];

    /** How a practice session felt. */
    const RATINGS = ['Rough', 'OK', 'Clean', 'Locked in'];

    /** The path: 12 rungs, each assuming the one below it. */
    const LADDER = [
        [
            't' => 'Gain staging',
            'd' => 'Every track leaves the channel at the same level, nothing in the red. Do this before anything else or you will chase volume all night.',
        ],
        [
            't' => 'Count the phrase',
            'd' => 'Find the 1. Count 32 bars. Bollywood tracks break phrase at the mukhda — learn to hear where.',
        ],
        [
            't' => 'Beatmatch by ear',
            'd' => 'Sync off, pitch fader only, headphones on one ear. Painful for two weeks, then permanent.',
        ],
        [
            't' => 'The EQ swap',
            'd' => 'Bass out on the incoming track, swap the low end on the phrase boundary. Never two kicks at once.',
        ],
        [
            't' => 'Ride the low end',
            'd' => 'One track owns the bass at any moment. This single habit is most of what makes a mix sound professional.',
        ],
        [
            't' => 'Loop and extend',
            'd' => 'Build an outro where the track has none — essential, because Bollywood originals almost never have a DJ-friendly tail.',
        ],
        [
            't' => 'Echo out',
            'd' => 'End a track with no exit: kill the bass, throw the echo, pull the fader on the beat.',
        ],
        [
            't' => 'Harmonic mixing',
            'd' => 'Camelot neighbours first (8A → 7A, 9A, 8B). Once that is automatic, learn which clashes are worth it.',
        ],
        [
            't' => 'The tempo bridge',
            'd' => 'Carry a 100 BPM mukhda into a 124 BPM groove. Percussion loop under it, pitch up over 16 bars, or ride an edit.',
        ],
        [
            't' => 'Stem swap',
            'd' => 'Bollywood vocal over a house instrumental, dropped on the phrase. This is the move your set will be known for.',
        ],
        [
            't' => 'Build a 60-minute arc',
            'd' => 'Opener, lift, peak, release. Plan it, then be willing to abandon it when the room says otherwise.',
        ],
        [
            't' => 'Record and re-listen',
            'd' => 'Record every practice set. Listen a week later, cold. You will hear the mistakes you could not hear live.',
        ],
    ];

    /** Starter rig: what to buy, why, and the rough price in USD (0 = free). */
    const RIG = [
        [
            't' => 'Pioneer DDJ-FLX4',
            'd' => '2-channel controller. rekordbox + Serato + djay, and it has track separation for stem swaps.',
            'price' => 299,
        ],
        [
            't' => 'Sennheiser HD25',
            'd' => 'The industry-standard DJ headphone. Sealed, loud, repairable. Buy once.',
            'price' => 149,
        ],
        [
            't' => 'rekordbox (free tier)',
            'd' => 'Prep tracks, set cue points, export to USB. Free is enough for months.',
            'price' => 0,
        ],
        [
            't' => '2× USB 3.0 sticks, exFAT',
            'd' => 'One is the backup. Clubs eat drives and you will lose one.',
            'price' => 25,
        ],
        [
            't' => 'RCA → 3.5mm cable',
            'd' => 'Gets you into any speaker in any room.',
            'price' => 10,
        ],
        [
            't' => 'Laptop stand',
            'd' => 'Saves your neck and your screen from drinks.',
            'price' => 25,
        ],
        [
            't' => 'Later: JBL 305P MkII',
            'd' => 'Monitors. Only once you are practising daily and the headphones are not enough.',
            'price' => 350,
        ],
    ];

    /** The Bollywood prep bench: 14 steps in 5 phases. Ticking them derives
     * a track's prep state — see Music::prepDerive(). */
    const PREPSTEPS = [
        [
            'phase' => 'Source',
            'steps' => [
                [
                    'id' => 'src',
                    't' => 'Start from a clean master',
                    'd' => 'Buy the original rather than pulling a rip. Most Bollywood files in circulation are lossy and already mastered loud — they smear on a big system and they fall apart under stem separation.',
                ],
                [
                    'id' => 'bpm',
                    't' => 'Confirm the true BPM',
                    'd' => 'Detection routinely halves or doubles on tabla and dhol patterns. Tap the tempo yourself: a track reading 85 is often really 170, and a “slow” 100 may be a 200 half-time feel.',
                ],
            ],
        ],
        [
            'phase' => 'Grid',
            'steps' => [
                [
                    'id' => 'grid',
                    't' => 'Grid from the first downbeat, not the first sound',
                    'd' => 'Plenty of tracks open with a free-time alaap, a sitar flourish or a line of film dialogue. Drop the first beatgrid marker on the first real kick.',
                ],
                [
                    'id' => 'drift',
                    't' => 'Chase the drift with extra markers',
                    'd' => 'Live percussion means the tempo wanders. Set a marker at every section change, then check the grid against the LAST chorus, not the first. If the end is out, the middle was already wrong.',
                ],
            ],
        ],
        [
            'phase' => 'Cue points',
            'steps' => [
                [
                    'id' => 'mukhda',
                    't' => 'Hot cue the mukhda',
                    'd' => 'The hook — what the room is actually waiting for. Make it cue 1. If you set one cue point on a track, set this one.',
                ],
                [
                    'id' => 'antara',
                    't' => 'Hot cue the antara',
                    'd' => 'The verse, where the energy usually dips. This is your exit: the point you start the blend out rather than riding it to the end.',
                ],
                [
                    'id' => 'perc',
                    't' => 'Find the percussion-only window',
                    'd' => 'Almost every Bollywood track has exactly one stretch of dhol or tabla with no vocal over it. That window is your blend-in and your blend-out. Mark both ends.',
                ],
                [
                    'id' => 'intro',
                    't' => 'Build the intro the track doesn’t have',
                    'd' => 'Loop 8 or 16 bars of that percussion window and save it as a loop-in cue. Originals start cold — you have to manufacture something to mix into.',
                ],
                [
                    'id' => 'outro',
                    't' => 'Build an outro',
                    'd' => 'Same job at the tail. These tracks end on a held vocal or a full-stop orchestral hit, never a DJ-friendly fade. Loop yourself a way out, or plan the echo now.',
                ],
            ],
        ],
        [
            'phase' => 'Level and key',
            'steps' => [
                [
                    'id' => 'key',
                    't' => 'Verify the key by ear',
                    'd' => 'Detection is unreliable on harmonium, massed strings and heavy vocal layering. Check against a track you already trust in that key before you build a harmonic move on it.',
                ],
                [
                    'id' => 'gain',
                    't' => 'Match gain to your loudest house track',
                    'd' => 'Bollywood masters run hot and squashed. Pull the channel trim down so the blend doesn’t jump 6 dB the moment it comes in.',
                ],
            ],
        ],
        [
            'phase' => 'Set-ready',
            'steps' => [
                [
                    'id' => 'stems',
                    't' => 'Run the stems and actually listen',
                    'd' => 'Separate the vocal and solo it. Dense string arrangements smear badly. If it smears, this is a play-it-straight track, not a stem-swap candidate — tag it so you remember.',
                ],
                [
                    'id' => 'bridge',
                    't' => 'Write down the tempo bridge',
                    'd' => 'Note how this one gets to 124: pitch up over 16 bars, a percussion loop underneath, or ride an edit. Put it in the track’s tags so it is there at 1am.',
                ],
                [
                    'id' => 'rehearse',
                    't' => 'Rehearse one in, one out',
                    'd' => 'In headphones, before it goes anywhere near a room. A track is not prepped until you have played the transition at both ends at least once.',
                ],
            ],
        ],
    ];

    /** The 9Bar promo checklist for a night: 13 steps in 4 phases, stored
     * per gig in gigs.promo. */
    const PROMOSTEPS = [
        [
            'phase' => 'Lock it in',
            'steps' => [
                [
                    'id' => 'date',
                    't' => 'Date, venue and set time confirmed',
                    'd' => 'In writing, not in a voice note. Get the set time specifically — opening at 9 and closing at 1 are different jobs and want different crates.',
                ],
                [
                    'id' => 'fee',
                    't' => 'Fee agreed in writing',
                    'd' => 'Even when it is a friend’s party and the answer is zero. Writing it down is what turns a favour into a booking, and it is how the one after this gets paid.',
                ],
                [
                    'id' => 'tech',
                    't' => 'Ask what is actually in the booth',
                    'd' => 'Mixer model, CDJs or controller, whether there is a spare RCA. Assume nothing is there until somebody names the model.',
                ],
            ],
        ],
        [
            'phase' => 'Announce',
            'steps' => [
                [
                    'id' => 'flyer',
                    't' => 'Flyer made',
                    'd' => 'One image that still reads at thumbnail size. Date, venue, time, 9Bar. Everything else on it is decoration.',
                ],
                [
                    'id' => 'post',
                    't' => 'Announcement posted to the grid',
                    'd' => 'Not only a story. Stories are gone in a day; the grid is what someone checks when they hear about the night a week later.',
                ],
                [
                    'id' => 'story',
                    't' => 'Story, then saved to a highlight',
                    'd' => 'Post it the day you announce, then pin it into a 9Bar highlight so it stays reachable instead of expiring.',
                ],
                [
                    'id' => 'venue',
                    't' => 'Venue cross-posted',
                    'd' => 'Tag them and ask them to share. Their audience is the one that already turns up to that room.',
                ],
            ],
        ],
        [
            'phase' => 'Fill the room',
            'steps' => [
                [
                    'id' => 'rsvp',
                    't' => 'RSVP or ticket link live',
                    'd' => 'Even a free night benefits from a list. It tells you whether to expect twenty or eighty, and that changes what you pack.',
                ],
                [
                    'id' => 'dm',
                    't' => 'DM the regulars personally',
                    'd' => 'The twenty people who actually come. One personal message converts better than any number of posts.',
                ],
                [
                    'id' => 'remind',
                    't' => 'Reminder 24 hours before',
                    'd' => 'Most people who meant to come simply forget. This is the highest-return line on the whole list.',
                ],
            ],
        ],
        [
            'phase' => 'After',
            'steps' => [
                [
                    'id' => 'recap',
                    't' => 'Recap posted within 48 hours',
                    'd' => 'A clip from the peak while people still remember being there. The recap is what books the next one.',
                ],
                [
                    'id' => 'ids',
                    't' => 'Track IDs shared',
                    'd' => 'Somebody always asks. Post the list — it is free content, and it is the reason people follow a DJ rather than a night.',
                ],
                [
                    'id' => 'logfee',
                    't' => 'Marked played, and thanks sent',
                    'd' => 'Update it under Gigs, and message whoever booked you. Being easy to work with gets you rebooked more reliably than being good does.',
                ],
            ],
        ],
    ];

    /**
     * The six example rows shown while the crate is still empty. They are not in
     * anybody's crate until the operator asks for them to be seeded.
     */
    const DEMO = [
        [
            'title' => 'Kesariya (Deep Edit)',
            'artist' => 'Pritam / bootleg',
            'bpm' => 122,
            'key' => '8A',
            'energy' => 5,
            'bucket' => 'edit',
            'prep' => 'ready',
            'tags' => 'opener, vocal',
        ],
        [
            'title' => 'Bass Rani',
            'artist' => 'Nucleya',
            'bpm' => 140,
            'key' => '5A',
            'energy' => 9,
            'bucket' => 'desi',
            'prep' => 'gridded',
            'tags' => 'peak, dhol',
        ],
        [
            'title' => 'Udd Gaye',
            'artist' => 'Ritviz',
            'bpm' => 104,
            'key' => '11B',
            'energy' => 6,
            'bucket' => 'bollywood',
            'prep' => 'raw',
            'tags' => 'needs bridge',
        ],
        [
            'title' => 'Percussion Tool 124',
            'artist' => '—',
            'bpm' => 124,
            'key' => '1A',
            'energy' => 3,
            'bucket' => 'tool',
            'prep' => 'ready',
            'tags' => 'bridge, loop',
        ],
        [
            'title' => 'Mai Ni Meriye',
            'artist' => 'Lost Stories',
            'bpm' => 124,
            'key' => '11B',
            'energy' => 7,
            'bucket' => 'house',
            'prep' => 'ready',
            'tags' => 'lift',
        ],
        [
            'title' => 'Afreen Afreen (acapella)',
            'artist' => 'Rahat Fateh Ali Khan',
            'bpm' => 0,
            'key' => '9B',
            'energy' => 4,
            'bucket' => 'tool',
            'prep' => 'gridded',
            'tags' => 'stem swap',
        ],
    ];

    /** The ids of the four source + grid steps that make a track 'gridded'. */
    const GRIDDED_STEPS = ['src', 'bpm', 'grid', 'drift'];

    /** Look up a bucket by id; falls back to the first bucket, as the artifact does. */
    public static function bucket(?string $id): array
    {
        foreach (self::BUCKETS as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }
        return self::BUCKETS[0];
    }

    /** Human label for a prep state id; unknown ids read as 'Raw'. */
    public static function prepLabel(?string $id): string
    {
        foreach (self::PREP as $p) {
            if ($p['id'] === $id) {
                return $p['label'];
            }
        }
        return self::PREP[0]['label'];
    }
}
