<?php

namespace App\Services\Safety;

/**
 * Who to call, and what to do while you wait.
 *
 * ---------------------------------------------------------------------------
 * Sources
 * ---------------------------------------------------------------------------
 *
 * Every number here is taken from the National Portal of India's official
 * helpline directory (india.gov.in/directory/helpline), except Tele-MANAS,
 * which comes from the Ministry of Health's own launch release (PIB PRID
 * 1866498). Nothing here is from memory, and nothing should be added from
 * memory either — a wrong number in a safety app is worse than no number,
 * because somebody dials it instead of dialling one that works.
 *
 * A few that "everybody knows" are wrong, which is exactly why they were
 * checked: the women's helpline is 181 (1091 is the anti-obscene-calls cell),
 * road accidents are 1073, and natural-calamity relief is 1070.
 *
 * ---------------------------------------------------------------------------
 * Why this lives on the server
 * ---------------------------------------------------------------------------
 *
 * The client renders whatever this returns and holds no numbers of its own.
 * That is the whole point: helplines get renumbered, states add their own,
 * and a mistake found on a Tuesday should be corrected on the Tuesday — not
 * whenever the slowest user next updates the app. A hardcoded emergency
 * number is a bug you cannot fix.
 *
 * ---------------------------------------------------------------------------
 * On 112
 * ---------------------------------------------------------------------------
 *
 * 112 is the single national emergency number (ERSS) and it routes to police,
 * fire and ambulance alike. So it is the primary action almost everywhere,
 * with the older service-specific number offered underneath. In a genuine
 * emergency the right advice is nearly always "dial 112" rather than "work
 * out which of 100, 101 and 102 you need" — and that judgement is encoded
 * here rather than left to somebody at the worst moment of their day.
 */
class EmergencyDirectory
{
    /**
     * Shown once, above everything.
     *
     * Deliberately plain. A safety app that overstates what it can do is
     * making a promise it will break at the worst possible time.
     */
    public const DISCLAIMER =
        'SFamily helps you reach emergency services faster — it does not '
        .'replace them. We do not dispatch help ourselves, and we cannot '
        .'guarantee a call connects. If you are in immediate danger, dial 112.';

    /**
     * The whole catalogue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            $this->police(),
            $this->ambulance(),
            $this->fire(),
            $this->women(),
            $this->child(),
            $this->cyber(),
            $this->disaster(),
            $this->road(),
            $this->railway(),
            $this->mentalHealth(),
            $this->senior(),
            $this->gas(),
            $this->forest(),
            $this->narcotics(),
        ];
    }

    /**
     * One category by key, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $service) {
            if ($service['key'] === $key) {
                return $service;
            }
        }

        return null;
    }

    /**
     * The Places types a category searches for, if it searches at all.
     *
     * @return array<int, string>
     */
    public function searchTypes(string $key): array
    {
        return $this->find($key)['search']['types'] ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | The services
    |--------------------------------------------------------------------------
    |
    | Guidance is short on purpose. Somebody reading this is frightened, on a
    | phone, possibly in the dark. Four lines they will actually read beats
    | twelve they will scroll past, and every line is something to *do*
    | rather than something to know.
    |
    */

    /**
     * @return array<string, mixed>
     */
    private function police(): array
    {
        return [
            'key' => 'police',
            'label' => 'Police',
            'tagline' => 'Crime, threat, or someone following you',
            'icon' => 'police',
            'tint' => '#4C8DFF',

            'call' => [
                ['number' => '112', 'label' => 'Emergency response', 'primary' => true],
                ['number' => '100', 'label' => 'Police control room', 'primary' => false],
            ],

            'search' => [
                'types' => ['police'],
                'label' => 'Nearest police stations',
            ],

            'guidance' => [
                'Get somewhere public and lit if you can — a shop, a petrol pump, a busy road.',
                'Stay on the line. The operator can trace you even if you cannot say where you are.',
                'If you cannot speak safely, stay connected and leave the line open.',
                'Your family can see your live location while this alert is running.',
            ],

            'warning' => null,
            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ambulance(): array
    {
        return [
            'key' => 'ambulance',
            'label' => 'Ambulance & Hospital',
            'tagline' => 'Injury, collapse, chest pain, breathing trouble',
            'icon' => 'ambulance',
            'tint' => '#FF5C7A',

            'call' => [
                ['number' => '108', 'label' => 'Emergency ambulance', 'primary' => true],
                ['number' => '102', 'label' => 'National Ambulance Service', 'primary' => false],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
            ],

            'search' => [
                'types' => ['hospital'],
                'label' => 'Nearest hospitals',
            ],

            'guidance' => [
                'Say the address first, before anything else. Everything else can follow.',
                'Do not move someone with a head, neck or back injury unless they are in danger where they lie.',
                'If they are unconscious but breathing, roll them onto their side.',
                'Send someone to the gate or the road to wave the ambulance in.',
            ],

            'warning' => null,

            'disclaimer' => 'Hospital listings come from Google Maps and may be '
                .'out of date. Call ahead before travelling to one.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fire(): array
    {
        return [
            'key' => 'fire',
            'label' => 'Fire Brigade',
            'tagline' => 'Fire, smoke, building collapse, people trapped',
            'icon' => 'fire',
            'tint' => '#FF8A3D',

            'call' => [
                ['number' => '101', 'label' => 'Fire & rescue', 'primary' => true],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
            ],

            'search' => [
                'types' => ['fire_station'],
                'label' => 'Nearest fire stations',
            ],

            'guidance' => [
                'Get out first, then call. Do not collect belongings.',
                'Stay low — smoke kills long before flame does.',
                'Feel a door before opening it. If it is hot, leave it shut and find another way.',
                'Never use a lift.',
            ],

            'warning' => 'If there is smoke, leaving comes before everything '
                .'else — including this app.',

            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function women(): array
    {
        return [
            'key' => 'women',
            'label' => 'Women’s Safety',
            'tagline' => 'Harassment, domestic violence, feeling unsafe',
            'icon' => 'women',
            'tint' => '#C77DFF',

            'call' => [
                ['number' => '181', 'label' => 'Women helpline (24x7)', 'primary' => true],
                ['number' => '1091', 'label' => 'Anti-obscene calls cell', 'primary' => false],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
                ['number' => '1800-2000-737', 'label' => 'Rape crisis helpline', 'primary' => false],
            ],

            'search' => [
                'types' => ['police'],
                'label' => 'Nearest police stations',
            ],

            'guidance' => [
                'Move towards people. A crowded place is safer than a locked door.',
                '181 is staffed around the clock and can arrange shelter, counselling and police help.',
                'You do not have to be in immediate danger to call. Being afraid is reason enough.',
                'Your family can see your live location while this alert is running.',
            ],

            'warning' => null,
            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function child(): array
    {
        return [
            'key' => 'child',
            'label' => 'Child Helpline',
            'tagline' => 'A child in danger, missing, or being harmed',
            'icon' => 'child',
            'tint' => '#4CD4B0',

            'call' => [
                ['number' => '1098', 'label' => 'Childline India (24x7)', 'primary' => true],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
            ],

            'search' => [
                'types' => ['police'],
                'label' => 'Nearest police stations',
            ],

            'guidance' => [
                'For a missing child, call immediately — there is no waiting period in India.',
                'Have a recent photo and what they were wearing ready.',
                'Search the immediate area while somebody else stays on the phone.',
                '1098 is free, confidential, and answers day or night.',
            ],

            'warning' => null,
            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cyber(): array
    {
        return [
            'key' => 'cyber',
            'label' => 'Cyber Crime',
            'tagline' => 'Online fraud, money stolen, blackmail, hacked account',
            'icon' => 'cyber',
            'tint' => '#38BDF8',

            'call' => [
                ['number' => '1930', 'label' => 'Cyber crime helpline', 'primary' => true],
            ],

            'search' => null,

            'link' => [
                'label' => 'Report at cybercrime.gov.in',
                'url' => 'https://cybercrime.gov.in',
            ],

            'guidance' => [
                'Call within the first hour. Money can often be frozen before it moves on.',
                'Do not delete anything — messages, emails and transaction texts are the evidence.',
                'Tell your bank to block the card or account as well as calling 1930.',
                'Never share an OTP, even with somebody claiming to be from the bank or the police.',
            ],

            'warning' => 'Nobody legitimate will ever ask you for an OTP, a PIN, '
                .'or remote access to your phone. Not your bank, not the police.',

            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function disaster(): array
    {
        return [
            'key' => 'disaster',
            'label' => 'Disaster & Weather',
            'tagline' => 'Flood, earthquake, cyclone, landslide',
            'icon' => 'disaster',
            'tint' => '#FBBF4C',

            'call' => [
                ['number' => '1070', 'label' => 'State relief commissioner', 'primary' => true],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
                ['number' => '011-24363260', 'label' => 'NDRF control room', 'primary' => false],
            ],

            'search' => [
                'types' => ['hospital'],
                'label' => 'Nearest hospitals & shelters',
            ],

            'guidance' => [
                'Move to higher ground for flooding; stay away from walls and windows in an earthquake.',
                'Never drive or walk through moving water — knee-deep water moves a car.',
                'Switch off the mains if water is rising indoors.',
                'Keep one phone charged and switch the others off.',
            ],

            'warning' => null,

            'disclaimer' => '1070 reaches your state relief commissioner. '
                .'Numbers vary by state — 112 will route you either way.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function road(): array
    {
        return [
            'key' => 'road',
            'label' => 'Road Accident',
            'tagline' => 'Crash, breakdown in traffic, someone hurt on the road',
            'icon' => 'road',
            'tint' => '#F97362',

            'call' => [
                ['number' => '1073', 'label' => 'Road accident helpline', 'primary' => true],
                ['number' => '108', 'label' => 'Emergency ambulance', 'primary' => false],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
            ],

            'search' => [
                'types' => ['hospital'],
                'label' => 'Nearest hospitals',
            ],

            'guidance' => [
                'Get yourself off the carriageway first. You cannot help anyone from under a truck.',
                'Hazard lights on, and a warning triangle well back if you have one.',
                'Do not pull anyone out of a vehicle unless there is fire or it is in water.',
                'Helping an accident victim carries no legal liability in India — the Good Samaritan law protects you.',
            ],

            'warning' => null,
            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function railway(): array
    {
        return [
            'key' => 'railway',
            'label' => 'Railway Emergency',
            'tagline' => 'Trouble on a train or at a station',
            'icon' => 'railway',
            'tint' => '#8B95F5',

            'call' => [
                ['number' => '139', 'label' => 'Railway security & medical', 'primary' => true],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
            ],

            'search' => [
                'types' => ['train_station'],
                'label' => 'Nearest stations',
            ],

            'guidance' => [
                'Note your coach and seat number before you call — it is the first thing asked.',
                '139 covers security, medical help and complaints on any Indian Railways service.',
                'For a medical emergency on board, tell the TTE as well as calling.',
                'Never cross the tracks, even at a stopped train.',
            ],

            'warning' => null,
            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mentalHealth(): array
    {
        return [
            'key' => 'mental_health',
            'label' => 'Mental Health',
            'tagline' => 'Distress, panic, thoughts of self-harm',
            'icon' => 'mental_health',
            'tint' => '#7FD8BE',

            'call' => [
                ['number' => '14416', 'label' => 'Tele-MANAS (24x7, free)', 'primary' => true],
                ['number' => '1800-91-4416', 'label' => 'Tele-MANAS toll free', 'primary' => false],
            ],

            'search' => null,

            'guidance' => [
                'Tele-MANAS is free, confidential, staffed day and night, and answers in your own language.',
                'You do not have to be in crisis to call. Wanting to talk is enough.',
                'If someone is in immediate danger of hurting themselves, do not leave them alone — call 112.',
                'Move somewhere you are not alone, if you can.',
            ],

            'warning' => null,

            'disclaimer' => 'Run by the Ministry of Health and Family Welfare. '
                .'Calls are confidential.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function senior(): array
    {
        return [
            'key' => 'senior',
            'label' => 'Senior Citizens',
            'tagline' => 'Elder abuse, neglect, or an older person needing help',
            'icon' => 'senior',
            'tint' => '#E9B872',

            'call' => [
                ['number' => '14567', 'label' => 'Elderline (24x7)', 'primary' => true],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
            ],

            'search' => [
                'types' => ['hospital'],
                'label' => 'Nearest hospitals',
            ],

            'guidance' => [
                'Elderline handles abuse, abandonment, pension problems and medical emergencies alike.',
                'For a fall, do not lift them — check for pain in the hip or back first, and call for help.',
                'Keep their medicines and prescriptions to hand when you call.',
                'The caller does not have to be the person in trouble.',
            ],

            'warning' => null,
            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gas(): array
    {
        return [
            'key' => 'gas',
            'label' => 'Gas Leak',
            'tagline' => 'Smell of LPG, hissing cylinder, suspected leak',
            'icon' => 'gas',
            'tint' => '#FFD166',

            'call' => [
                ['number' => '1906', 'label' => 'LPG emergency helpline', 'primary' => true],
                ['number' => '101', 'label' => 'Fire & rescue', 'primary' => false],
            ],

            'search' => [
                'types' => ['fire_station'],
                'label' => 'Nearest fire stations',
            ],

            'guidance' => [
                'Do not touch any switch — not on, not off. A spark is a spark.',
                'Open the doors and windows, close the cylinder valve, and get everyone out.',
                'Make the call from outside the building, not inside it.',
                'No flame, no lighter, no matchstick, until somebody has checked it.',
            ],

            'warning' => 'Switching a light on or off can ignite leaked gas. '
                .'Leave the switches alone and get out.',

            'disclaimer' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forest(): array
    {
        return [
            'key' => 'forest',
            'label' => 'Forest & Wildlife',
            'tagline' => 'Forest fire, animal in a village, poaching',
            'icon' => 'forest',
            'tint' => '#5FD068',

            'call' => [
                ['number' => '112', 'label' => 'Emergency response', 'primary' => true],
                ['number' => '1926', 'label' => 'Forest fire (most states)', 'primary' => false],
            ],

            'search' => [
                'types' => ['national_park', 'park'],
                'label' => 'Forest areas & offices nearby',
            ],

            'guidance' => [
                'Never approach a wild animal, even an injured one, and never crowd it.',
                'Keep people and livestock indoors and give the animal a clear way out.',
                'For a forest fire, report the location and the direction of the wind.',
                'Do not attempt a rescue yourself — wait for the forest department.',
            ],

            'warning' => null,

            'disclaimer' => 'India has no single national forest helpline. 1926 '
                .'works in most states for forest fires; 112 will route you '
                .'to your state forest department either way.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function narcotics(): array
    {
        return [
            'key' => 'narcotics',
            'label' => 'Drugs & Addiction',
            'tagline' => 'Substance abuse, overdose, or reporting drug crime',
            'icon' => 'narcotics',
            'tint' => '#B39DDB',

            'call' => [
                ['number' => '1933', 'label' => 'MANAS national helpline', 'primary' => true],
                ['number' => '14416', 'label' => 'Tele-MANAS counselling', 'primary' => false],
                ['number' => '112', 'label' => 'Emergency response', 'primary' => false],
            ],

            'search' => [
                'types' => ['hospital'],
                'label' => 'Nearest hospitals',
            ],

            'guidance' => [
                'For a suspected overdose, call 108 first and stay with them.',
                'If they are unconscious but breathing, roll them onto their side.',
                'Take whatever they took, or its packet, to the hospital with you.',
                'MANAS handles both reporting drug crime and asking for help with addiction.',
            ],

            'warning' => null,
            'disclaimer' => null,
        ];
    }
}
