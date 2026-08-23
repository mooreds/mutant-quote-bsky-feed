<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/textsim.php';
require_once __DIR__ . '/../src/jetstream.php';

$pass = 0;
$fail = 0;

function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

function check_mutation(string $name, array $result, bool $expectMutation, ?string $expectReason = null): void
{
    check(
        $name,
        $result['isMutation'] === $expectMutation
            && ($expectReason === null || $result['reason'] === $expectReason)
    );
}

echo "normalize_text\n";
check('collapses whitespace and invisible chars', normalize_text("  hello \n\t world \u{200B}! ") === 'hello world !');

echo "levenshtein_utf8\n";
check('kitten/sitting = 3', levenshtein_utf8('kitten', 'sitting') === 3);
check('flaw/lawn = 2', levenshtein_utf8('flaw', 'lawn') === 2);
check('empty/abc = 3', levenshtein_utf8('', 'abc') === 3);
check('same/same = 0', levenshtein_utf8('same', 'same') === 0);
check('utf-8: héllo/hello = 1', levenshtein_utf8('héllo', 'hello') === 1);
check('early bail respects max', levenshtein_utf8('kitten', 'sitting', 2) === 3);
check('emoji codepoints counted singly', levenshtein_utf8('👍a', 'a') === 1);

echo "detect_mutation\n";
$same = 'The cat sat on the mat and stared at the dog';
check_mutation('identical is not a mutation', detect_mutation($same, $same), false, 'identical');
check_mutation(
    'whitespace-only diff is not a mutation',
    detect_mutation($same, "The cat  sat on the mat\nand stared at the dog "),
    false,
    'identical'
);

$typoParent = 'The quick brown fox jumps over the lazy dog near the barn';
$typoChild = 'The quick brown fox jump over the lazy dog near the barn';
$r = detect_mutation($typoParent, $typoChild);
check_mutation('small typo is a mutation', $r, true, 'mutated');
check('small typo similarity >= 0.9', $r['similarity'] >= 0.9);

$swapParent = 'I told him the meeting was at noon but he never showed up at all';
$swapChild = 'I told him the meeting was at dawn but he never showed up at all';
check_mutation('swapped word is a mutation', detect_mutation($swapParent, $swapChild), true, 'mutated');

check_mutation(
    'completely different is not',
    detect_mutation(
        'The quick brown fox jumps over the lazy dog near the barn',
        'Totally unrelated commentary about local sports and weather today folks'
    ),
    false
);
check_mutation('very short ignored', detect_mutation('lol', 'lmao'), false, 'too-short');
check_mutation(
    'big addition is not a mutation',
    detect_mutation(
        'This is a reasonably long sentence about breakfast foods in general',
        'This is a reasonably long sentence about breakfast foods in general plus an enormous rambling addition that triples the length for no reason whatsoever'
    ),
    false
);

$cupParent =
    'Optimist: The cup is half full. Pessimist: The cup is half empty. '
    . 'Science Fiction Writer: The cup is a crystalline supercomputer and the liquid '
    . 'is hyperintelligent and wants to know why humanity should be allowed to exist';
$cupChild =
    'Optimist: The cup is half full. Pessimist: The cup is half empty. '
    . 'Web Designer: .cup { opacity: 0.5 }';
check_mutation('template remix (Scalzi-style) is a mutation', detect_mutation($cupParent, $cupChild), true);

check_mutation(
    'truncation of parent is a mutation',
    detect_mutation(
        'The mayor announced today that the old bridge will finally be replaced after decades of complaints from residents',
        'The mayor announced today that the old bridge will finally be replaced'
    ),
    true
);
check_mutation(
    'unrelated texts are not',
    detect_mutation(
        'The mayor announced today that the old bridge will finally be replaced after decades',
        'Optimist: The cup is half full. Pessimist: The cup is half empty.'
    ),
    false
);
check_mutation(
    'copy + long commentary is low-coverage, not mutation',
    detect_mutation(
        'The mayor announced today that the old bridge will finally be replaced after decades',
        'The mayor announced today that the old bridge will finally be replaced after decades. '
        . 'Honestly I cannot believe it took this long, I remember when they first talked about '
        . 'this project when I was in school and nothing ever came of it until now apparently'
    ),
    false,
    'low-coverage'
);

echo "extract_quoted_uri\n";
check(
    'app.bsky.embed.record',
    extract_quoted_uri([
        '$type' => 'app.bsky.feed.post',
        'text' => 'look',
        'embed' => [
            '$type' => 'app.bsky.embed.record',
            'record' => ['uri' => 'at://did:plc:abc/app.bsky.feed.post/123', 'cid' => 'bafy'],
        ],
    ]) === 'at://did:plc:abc/app.bsky.feed.post/123'
);
check(
    'recordWithMedia',
    extract_quoted_uri([
        '$type' => 'app.bsky.feed.post',
        'embed' => [
            '$type' => 'app.bsky.embed.recordWithMedia',
            'record' => ['record' => ['uri' => 'at://did:plc:x/app.bsky.feed.post/456', 'cid' => 'bafy']],
            'media' => ['$type' => 'app.bsky.embed.images'],
        ],
    ]) === 'at://did:plc:x/app.bsky.feed.post/456'
);
check('plain post has none', extract_quoted_uri(['text' => 'plain']) === null);
check('image-only post has none', extract_quoted_uri(['embed' => ['$type' => 'app.bsky.embed.images', 'images' => []]]) === null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
