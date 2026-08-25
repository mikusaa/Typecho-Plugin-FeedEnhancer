<?php

/**
 * Activates FeedEnhancer and installs deterministic cross-database fixtures.
 */

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Integration;

$typechoRoot = getenv('TYPECHO_ROOT');
if (!is_string($typechoRoot) || '' === $typechoRoot) {
    fwrite(STDERR, "TYPECHO_ROOT is required.\n");
    exit(1);
}

$configFile = rtrim($typechoRoot, '/\\') . '/config.inc.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Typecho config.inc.php is missing.\n");
    exit(1);
}

$siteUrl = getenv('TYPECHO_SITE_URL');
if (!is_string($siteUrl) || '' === $siteUrl) {
    $siteUrl = getenv('FE_HTTP_ROOT');
}
if (!is_string($siteUrl) || '' === $siteUrl) {
    $siteUrl = 'http://127.0.0.1:18080';
}

$siteParts = parse_url($siteUrl);
if (false === $siteParts || !isset($siteParts['host'])) {
    fwrite(STDERR, "TYPECHO_SITE_URL is invalid.\n");
    exit(1);
}

$sitePort = isset($siteParts['port'])
    ? (int) $siteParts['port']
    : ('https' === ($siteParts['scheme'] ?? '') ? 443 : 80);
$hostHeader = (string) $siteParts['host'];
if (!in_array($sitePort, [80, 443], true)) {
    $hostHeader .= ':' . $sitePort;
}

$_SERVER['HTTP_HOST'] = $hostHeader;
$_SERVER['SERVER_NAME'] = (string) $siteParts['host'];
$_SERVER['SERVER_PORT'] = (string) $sitePort;
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

if (!defined('__TYPECHO_ROOT_URL__')) {
    define('__TYPECHO_ROOT_URL__', rtrim($siteUrl, '/'));
}

if (!defined('__TYPECHO_DEBUG__')) {
    define('__TYPECHO_DEBUG__', true);
}

require $configFile;
\Widget\Init::alloc();

$mode = $argv[1] ?? 'seed';

/** @param array<string,string> $overrides */
function configureFeedEnhancer(array $overrides = []): void
{
    \Utils\Helper::configPlugin('FeedEnhancer', array_merge([
        'feedContentMode' => '0',
        'feedContentLength' => '300',
        'feedReadMoreText' => '阅读全文',
        'stylesheetEnabled' => '1',
        'safariXmlMime' => '0',
        'mediaEnabled' => '1',
        'mediaFieldNames' => 'banner,cover,thumbnail',
    ], $overrides));
}

if ('safari-on' === $mode) {
    configureFeedEnhancer([
        'safariXmlMime' => '1',
    ]);
    fwrite(STDOUT, "Enabled Safari XML MIME compatibility.\n");
    exit(0);
}

if ('truncation-on' === $mode) {
    configureFeedEnhancer([
        'feedContentMode' => '1',
        'feedContentLength' => '50',
        'feedReadMoreText' => 'FE-CI-READ-MORE',
    ]);
    fwrite(STDOUT, "Enabled Feed content truncation.\n");
    exit(0);
}

if ('truncation-on-full-text' === $mode) {
    \Utils\Helper::setOption('feedFullText', 1);
    configureFeedEnhancer([
        'feedContentMode' => '1',
        'feedContentLength' => '50',
        'feedReadMoreText' => 'FE-CI-READ-MORE',
    ]);
    fwrite(STDOUT, "Enabled Feed content truncation with feedFullText=1.\n");
    exit(0);
}

if ('seed' !== $mode) {
    fwrite(STDERR, "Unknown preparation mode: {$mode}\n");
    exit(1);
}

$pluginFile = rtrim($typechoRoot, '/\\') . '/usr/plugins/FeedEnhancer/Plugin.php';
if (!is_file($pluginFile)) {
    fwrite(STDERR, "FeedEnhancer/Plugin.php is missing from Typecho.\n");
    exit(1);
}

require_once $pluginFile;

$plugins = \Typecho\Plugin::export();
if (isset($plugins['activated']['FeedEnhancer'])) {
    fwrite(STDERR, "FeedEnhancer is already active in this fixture database.\n");
    exit(1);
}

\TypechoPlugin\FeedEnhancer\Plugin::activate();
\Typecho\Plugin::activate('FeedEnhancer');

$probeFile = rtrim($typechoRoot, '/\\')
    . '/usr/plugins/FeedContractProbe/Plugin.php';
if (!is_file($probeFile)) {
    fwrite(STDERR, "FeedContractProbe/Plugin.php is missing from Typecho.\n");
    exit(1);
}

require_once $probeFile;
\TypechoPlugin\FeedContractProbe\Plugin::activate();
\Typecho\Plugin::activate('FeedContractProbe');

\Utils\Helper::setOption('plugins', \Typecho\Plugin::export());
\Utils\Helper::setOption('rewrite', 1);
\Utils\Helper::setOption('feedFullText', 0);
configureFeedEnhancer();

$db = \Typecho\Db::get();

/** @param array<string,mixed> $row */
function insertFixture(\Typecho\Db $db, string $table, array $row): void
{
    $db->query($db->insert('table.' . $table)->rows($row));
}

// Remove installer sample data so current-time rows cannot cross the
// visibility boundary between two requests in the same contract run.
foreach (['comments', 'relationships', 'fields', 'contents'] as $table) {
    $db->query($db->delete('table.' . $table));
}

$created = 1577966400; // 2020-01-02 12:00:00 UTC.
$modified = 1609588800; // 2021-01-02 12:00:00 UTC.

$editorPassword = 'feed-ci-editor-password';
$editorAuthCode = 'feed-ci-editor-auth-code';
$passwordHasher = new \Utils\PasswordHash(8, true);

insertFixture($db, 'users', [
    'uid' => 2,
    'name' => 'feed-secret-author',
    'password' => $passwordHasher->hashPassword($editorPassword),
    'mail' => 'feed-secret-author@example.invalid',
    'url' => 'https://secret-author.example.invalid/',
    'screenName' => 'FE-SECRET-AUTHOR-SENTINEL',
    'created' => $created,
    'activated' => 0,
    'logged' => 0,
    'group' => 'editor',
    'authCode' => $editorAuthCode,
]);

$contents = [
    [
        'cid' => 100,
        'title' => 'FE-PUBLIC-SEARCH-TITLE',
        'slug' => 'fe-public-visible',
        'created' => $created,
        'modified' => $modified,
        'text' => '<p>FE-PUBLIC-BODY-SENTINEL</p><img src="/media/body.jpg" alt="">',
        'authorId' => 1,
        'type' => 'post',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 0,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 101,
        'title' => 'FE-HIDDEN-TITLE-SENTINEL',
        'slug' => 'fe-hidden',
        'created' => $created,
        'modified' => $created,
        'text' => '<p>FE-HIDDEN-BODY-SENTINEL</p>',
        'authorId' => 2,
        'type' => 'post',
        'status' => 'hidden',
        'password' => null,
        'commentsNum' => 1,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 102,
        'title' => 'FE-PRIVATE-TITLE-SENTINEL',
        'slug' => 'fe-private-slug-sentinel',
        'created' => $created,
        'modified' => $created,
        'text' => '<p>FE-PRIVATE-BODY-SENTINEL</p>',
        'authorId' => 2,
        'type' => 'post',
        'status' => 'private',
        'password' => null,
        'commentsNum' => 10,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 103,
        'title' => 'FE-PASSWORD-TITLE-SENTINEL',
        'slug' => 'fe-password',
        'created' => $created,
        'modified' => $created,
        'text' => '<p>FE-PASSWORD-BODY-SENTINEL</p>',
        'authorId' => 2,
        'type' => 'post',
        'status' => 'publish',
        'password' => 'feed-password-sentinel',
        'commentsNum' => 1,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 104,
        'title' => 'FE-NOFEED-TITLE-SENTINEL',
        'slug' => 'fe-no-feed',
        'created' => $created,
        'modified' => $created,
        'text' => '<p>FE-NOFEED-BODY-SENTINEL</p>',
        'authorId' => 2,
        'type' => 'post',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 1,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 0,
        'parent' => 0,
    ],
    [
        'cid' => 105,
        'title' => 'FE-WAITING-TITLE-SENTINEL',
        'slug' => 'fe-waiting',
        'created' => $created,
        'modified' => $created,
        'text' => '<p>FE-WAITING-BODY-SENTINEL</p>',
        'authorId' => 2,
        'type' => 'post',
        'status' => 'waiting',
        'password' => null,
        'commentsNum' => 0,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 106,
        'title' => 'FE-FUTURE-TITLE-SENTINEL',
        'slug' => 'fe-future',
        'created' => 2000000000,
        'modified' => 2000000000,
        'text' => '<p>FE-FUTURE-BODY-SENTINEL</p>',
        'authorId' => 2,
        'type' => 'post',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 1,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 107,
        'title' => 'FE-DRAFT-TITLE-SENTINEL',
        'slug' => 'fe-draft',
        'created' => $created,
        'modified' => $created,
        'text' => '<p>FE-DRAFT-BODY-SENTINEL</p>',
        'authorId' => 2,
        'type' => 'post',
        'status' => 'draft',
        'password' => null,
        'commentsNum' => 0,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 108,
        'title' => 'FE-REVISION-TITLE-SENTINEL',
        'slug' => 'fe-revision',
        'created' => $created,
        'modified' => $created,
        'text' => '<p>FE-REVISION-BODY-SENTINEL</p>',
        'authorId' => 2,
        'type' => 'post_draft',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 0,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 100,
    ],
    [
        'cid' => 120,
        'title' => 'FE-PUBLIC-COMMENT-PARENT',
        'slug' => 'fe-public-comments',
        'created' => $created + 60,
        'modified' => $created + 60,
        'text' => '<p>FE-PUBLIC-COMMENT-PARENT-BODY</p>',
        'authorId' => 1,
        'type' => 'post',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 10,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 121,
        'title' => 'FE-MORE-TITLE-SENTINEL',
        'slug' => 'fe-more-content',
        'created' => $created + 120,
        'modified' => $created + 120,
        'text' => '<p>FE-MORE-LEAD-' . str_repeat('X', 60) . '</p><!--more-->'
            . '<p>FE-MORE-TAIL-SENTINEL</p><img src="/media/more-body.jpg" alt="">',
        'authorId' => 1,
        'type' => 'post',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 0,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 122,
        'title' => 'FE-NOMORE-TITLE-SENTINEL',
        'slug' => 'fe-no-more-content',
        'created' => $created + 180,
        'modified' => $created + 180,
        'text' => '<p>FE-NOMORE-LEAD-' . str_repeat('Y', 60) . '</p>'
            . '<img src="/media/no-more-body.jpg" alt="">'
            . '<p>FE-NOMORE-TAIL-SENTINEL</p>',
        'authorId' => 1,
        'type' => 'post',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 0,
        'allowComment' => 1,
        'allowPing' => 1,
        'allowFeed' => 1,
        'parent' => 0,
    ],
    [
        'cid' => 123,
        'title' => 'FE-NOMORE-ATTACHMENT',
        'slug' => 'fe-no-more-attachment.jpg',
        'created' => $created + 181,
        'modified' => $created + 181,
        'text' => json_encode([
            'name' => 'fe-no-more-attachment.jpg',
            'path' => '/media/no-more-attachment.jpg',
            'size' => 1024,
            'type' => 'jpg',
            'mime' => 'image/jpeg',
        ]),
        'authorId' => 1,
        'type' => 'attachment',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 0,
        'allowComment' => 0,
        'allowPing' => 0,
        'allowFeed' => 1,
        'parent' => 122,
    ],
    [
        'cid' => 124,
        'title' => 'FE-FIELD-PRIORITY-ATTACHMENT',
        'slug' => 'fe-field-priority-attachment.jpg',
        'created' => $created + 1,
        'modified' => $created + 1,
        'text' => json_encode([
            'name' => 'fe-field-priority-attachment.jpg',
            'path' => '/media/field-priority-attachment.jpg',
            'size' => 2048,
            'type' => 'jpg',
            'mime' => 'image/jpeg',
        ]),
        'authorId' => 1,
        'type' => 'attachment',
        'status' => 'publish',
        'password' => null,
        'commentsNum' => 0,
        'allowComment' => 0,
        'allowPing' => 0,
        'allowFeed' => 1,
        'parent' => 100,
    ],
];

foreach ($contents as $content) {
    insertFixture($db, 'contents', $content);
}

insertFixture($db, 'fields', [
    'cid' => 100,
    'name' => 'cover',
    'type' => 'str',
    'str_value' => '/media/field-cover.jpg',
    'int_value' => 0,
    'float_value' => 0,
]);

insertFixture($db, 'metas', [
    'mid' => 10,
    'name' => 'Feed CI category',
    'slug' => 'feed-ci-category',
    'type' => 'category',
    'description' => 'Feed CI category',
    'count' => 1,
    'order' => 0,
    'parent' => 0,
]);
insertFixture($db, 'metas', [
    'mid' => 11,
    'name' => 'Feed CI tag',
    'slug' => 'feed-ci-tag',
    'type' => 'tag',
    'description' => 'Feed CI tag',
    'count' => 1,
    'order' => 0,
    'parent' => 0,
]);
insertFixture($db, 'relationships', ['cid' => 100, 'mid' => 10]);
insertFixture($db, 'relationships', ['cid' => 100, 'mid' => 11]);

for ($index = 0; $index < 10; ++$index) {
    insertFixture($db, 'comments', [
        'coid' => 200 + $index,
        'cid' => 120,
        'created' => $created + 200 + $index,
        'author' => sprintf('FE-PUBLIC-COMMENT-AUTHOR-%02d', $index),
        'authorId' => 0,
        'ownerId' => 1,
        'mail' => sprintf('public-%02d@example.test', $index),
        'url' => 'https://public-comment.example.test/',
        'ip' => '127.0.0.1',
        'agent' => 'FeedEnhancer CI',
        'text' => sprintf('FE-PUBLIC-COMMENT-%02d', $index),
        'type' => 'comment',
        'status' => 'approved',
        'parent' => 0,
    ]);

    insertFixture($db, 'comments', [
        'coid' => 300 + $index,
        'cid' => 102,
        'created' => $created + 300 + $index,
        'author' => sprintf('FE-PRIVATE-COMMENT-AUTHOR-%02d', $index),
        'authorId' => 0,
        'ownerId' => 2,
        'mail' => sprintf('private-%02d@example.invalid', $index),
        'url' => 'https://private-comment.example.invalid/',
        'ip' => '127.0.0.1',
        'agent' => 'FeedEnhancer CI',
        'text' => sprintf('FE-PRIVATE-COMMENT-%02d', $index),
        'type' => 'comment',
        'status' => 'approved',
        'parent' => 0,
    ]);
}

$specialComments = [
    [150, 101, 'FE-HIDDEN-COMMENT-SENTINEL', 'approved'],
    [151, 103, 'FE-PASSWORD-COMMENT-SENTINEL', 'approved'],
    [152, 104, 'FE-NOFEED-COMMENT-SENTINEL', 'approved'],
    [153, 106, 'FE-FUTURE-COMMENT-SENTINEL', 'approved'],
    [400, 120, 'FE-SPAM-COMMENT-SENTINEL', 'spam'],
    [401, 9999, 'FE-ORPHAN-COMMENT-SENTINEL', 'approved'],
];

foreach ($specialComments as [$coid, $cid, $text, $status]) {
    insertFixture($db, 'comments', [
        'coid' => $coid,
        'cid' => $cid,
        'created' => $created + $coid,
        'author' => $text . '-AUTHOR',
        'authorId' => 0,
        'ownerId' => 2,
        'mail' => strtolower($text) . '@example.invalid',
        'url' => 'https://filtered-comment.example.invalid/',
        'ip' => '127.0.0.1',
        'agent' => 'FeedEnhancer CI',
        'text' => $text,
        'type' => 'comment',
        'status' => $status,
        'parent' => 0,
    ]);
}

$fixtureState = getenv('FE_FIXTURE_STATE');
if (is_string($fixtureState) && '' !== $fixtureState) {
    $cookiePrefix = \Typecho\Cookie::getPrefix();
    $cookieHash = md5($editorAuthCode);
    $state = [
        'editorName' => 'feed-secret-author',
        'editorPassword' => $editorPassword,
        'cookieHeader' => $cookiePrefix . '__typecho_uid=2; '
            . $cookiePrefix . '__typecho_authCode=' . $cookieHash,
    ];

    $encodedState = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (false === $encodedState || false === file_put_contents($fixtureState, $encodedState . "\n")) {
        fwrite(STDERR, "Unable to write FE_FIXTURE_STATE.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Activated FeedEnhancer and installed integration fixtures.\n");
