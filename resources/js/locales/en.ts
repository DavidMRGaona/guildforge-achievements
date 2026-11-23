export default {
    achievements: {
        title: 'Achievements',
        rarity: {
            nobody: 'No active person holds it yet',
            share: 'Held by {share} % of active people',
        },
        profile: {
            secretMore: 'And {count} secret achievement. | And {count} secret achievements.',
            secretOnly: '{count} secret achievement. | {count} secret achievements.',
            earned: 'Earned ({count})',
            locked: 'Still to get ({count})',
            progress: '{value} of {threshold}',
            progressMonths: '{value} of {threshold} months in a row',
            manual: 'Granted by hand.',
            thisSeason: 'Season {season}',
            seasonsGot: 'Got in {seasons}',
            featured: 'Featured',
            feature: 'Feature «{name}»',
            featuredFull: 'You already feature {max}: remove one to feature another.',
            featuredChosen:
                'You feature {count} of {max} next to your name. Press an achievement’s star to change them.',
            featuredAuto:
                'Your {max} rarest achievements show next to your name. Press an achievement’s star to choose them yourself.',
            featuredAdded: '«{name}» featured.',
            featuredRemoved: '«{name}» is no longer featured.',
            showToOthers: 'Show my achievements to others',
            shownHelp:
                'Whoever may see your profile sees your achievements, how many and the featured ones next to your name.',
            hiddenHelp:
                'Only you see your achievements: not on your profile, not next to your name in the forum.',
            nowHidden: 'Only you see your achievements now.',
            nowShown: 'Your achievements show on your profile again.',
            saveFailed: 'It could not be saved. Try again.',
            readOnly: 'Nothing is saved while the site is viewed as another person.',
            tooMany: 'Too many changes in a row. Wait a moment and try again.',
            seeAll: 'See every achievement',
        },
        challenges: {
            title: 'Association challenges',
            subtitle:
                'Goals for everybody together. Whoever adds to one gets its reward achievement once it is reached.',
            progress: '{progress} of {target} {unit}',
            days: 'From {start} to {end}',
            membersOnly: 'Members only',
            states: {
                upcoming: 'Coming soon',
                running: 'Under way',
                completed: 'Reached',
                ended: 'Ended',
                closed: 'Closed',
            },
            contributors:
                'Nobody has added to it yet | {count} person has added to it | {count} people have added to it',
            yourContribution: 'You took part {count} time | You took part {count} times',
            noContribution: 'You have not added to it yet',
            reward: 'Reward',
            peopleTitle: 'Some of the people who reached it:',
            notCounted: 'Not counted yet. Goal: {target} {unit}.',
            block: {
                until: 'Until {day}',
                startsOn: 'Starts on {day}',
                endedOn: 'Ended on {day}',
                seeAll: 'See the challenges',
            },
        },
        account: {
            tabLabel: 'Achievements',
            title: 'Achievements',
            announceRare: {
                label: 'Announce my rare achievements',
                help: 'Once a month, whoever can see your profile gets a summary with last month’s achievements of yours held by fewer than {threshold} % of active people and your challenge ones. It only tells each achievement’s name: neither the day nor the time.',
                hidden: 'Your achievements are hidden right now: none is announced.',
                on: 'Your rare achievements will be announced.',
                off: 'Your achievements are no longer announced.',
            },
            inLeaderboard: {
                label: 'Appear in the leaderboard',
                help: 'Your name and points show in the achievements leaderboard, which only signed-in people who can see your profile see.',
                helpOff:
                    'The leaderboard is off right now. If it is switched on, you only appear if this is on.',
                on: 'You appear in the leaderboard.',
                off: 'You no longer appear in the leaderboard.',
            },
            notices: 'The bell’s achievement notices are chosen in',
            noticesLink: 'Notices',
        },
        catalogue: {
            title: 'Achievements',
            subtitle: 'Badges earned by playing, running games and taking part in the association.',
            held: 'You hold {count} of {total}.',
            empty: 'There are no achievements yet.',
            rarityExplained:
                'Rarity is the share of active people (who took part in something in the last twelve months) holding each achievement.',
            youHoldIt: 'You hold it',
            seasonExplained:
                'Some achievements can be got again every season, which lasts a calendar year. This is season {season}.',
            repeats: 'Every season',
            youHoldItThisSeason: 'You hold it this season',
            pastSeasons: 'Also in {seasons}',
            progress: 'This season: {value} of {threshold}',
            progressMonths: 'This season: {value} of {threshold} months in a row',
        },
    },
};
