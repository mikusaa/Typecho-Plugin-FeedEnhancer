<?php

declare(strict_types=1);

use Typecho\Db;
use Typecho\Db\Query;
use TypechoPlugin\FeedEnhancer\Feed\SecureRecentComments;

$typechoRoot = getenv('TYPECHO_SOURCE_ROOT');
if (!is_string($typechoRoot) || !is_file($typechoRoot . '/var/Typecho/Common.php')) {
    fwrite(STDERR, "TYPECHO_SOURCE_ROOT must point to a Typecho source tree.\n");
    exit(1);
}

if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', $typechoRoot);
}

if (!defined('__TYPECHO_PLUGIN_DIR__')) {
    define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
}

require_once $typechoRoot . '/var/Typecho/Common.php';
\Typecho\Common::init();
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
});

$driver = strtolower((string) getenv('FE_DB_DRIVER'));
$adapter = [
    'sqlite' => 'Pdo_SQLite',
    'mysql' => 'Pdo_Mysql',
    'pgsql' => 'Pdo_Pgsql',
][$driver] ?? null;

if (null === $adapter) {
    fwrite(STDERR, "FE_DB_DRIVER must be sqlite, mysql, or pgsql.\n");
    exit(1);
}

$config = databaseConfig($driver);
$db = new Db($adapter, 'fe_');
$db->addServer($config, Db::READ | Db::WRITE);
Db::set($db);

createSchema($db);
seedVisibilityMatrix($db);

$buildQuery = Closure::bind(
    static function (
        Db $database,
        int $pageSize,
        bool $commentOnly,
        bool $ignoreAuthor
    ): Query {
        return SecureRecentComments::globalFeedSelect(
            $database,
            1000,
            $pageSize,
            $commentOnly,
            $ignoreAuthor
        );
    },
    null,
    SecureRecentComments::class
);
$query = $buildQuery($db, 10, true, false);

if (!$query instanceof Query) {
    throw new RuntimeException('SecureRecentComments did not build a Typecho query.');
}

$sql = $query->prepare((string) $query);
$rows = $db->fetchAll($query);
$coids = array_map('intval', array_column($rows, 'coid'));
$expected = range(209, 200);

assertSame($expected, $coids, 'Expected 10 public comments after filtering invisible newer rows before LIMIT.');
assertOrder($sql, 'INNER JOIN', 'WHERE');
assertOrder($sql, 'WHERE', 'ORDER BY');
assertOrder($sql, 'ORDER BY', 'LIMIT 10');

$allRows = $db->fetchAll($buildQuery($db, 100, false, false));
$allCoids = array_map('intval', array_column($allRows, 'coid'));
assertContains(100, $allCoids, 'A comment on a published page must be included.');
assertContains(101, $allCoids, 'A non-comment row on a published attachment must be included when allowed.');
assertContains(103, $allCoids, 'A non-comment row must be included when comment-only mode is disabled.');
assertContains(104, $allCoids, 'An author comment must be included when ignoreAuthor is disabled.');
assertNotContains(102, $allCoids, 'A comment on an unsupported parent type must be excluded.');

$commentOnlyRows = $db->fetchAll($buildQuery($db, 100, true, false));
$commentOnlyCoids = array_map('intval', array_column($commentOnlyRows, 'coid'));
assertContains(100, $commentOnlyCoids, 'A normal page comment must survive comment-only mode.');
assertNotContains(101, $commentOnlyCoids, 'Attachment trackbacks must be excluded in comment-only mode.');
assertNotContains(103, $commentOnlyCoids, 'Post trackbacks must be excluded in comment-only mode.');

$ignoreAuthorRows = $db->fetchAll($buildQuery($db, 100, false, true));
$ignoreAuthorCoids = array_map('intval', array_column($ignoreAuthorRows, 'coid'));
assertNotContains(104, $ignoreAuthorCoids, 'Author comments must be excluded when ignoreAuthor is enabled.');

fwrite(STDOUT, sprintf(
    "%s: returned public coids %s; JOIN/WHERE/ORDER/LIMIT order verified.\n",
    $driver,
    implode(',', $coids)
));

/** @return array<string,mixed> */
function databaseConfig(string $driver): array
{
    if ('sqlite' === $driver) {
        $file = tempnam(sys_get_temp_dir(), 'feed-enhancer-db-');
        if (false === $file) {
            throw new RuntimeException('Could not create the temporary SQLite database.');
        }

        register_shutdown_function(static function () use ($file): void {
            if (is_file($file)) {
                unlink($file);
            }
        });

        return ['file' => $file];
    }

    return [
        'host' => requiredEnv('FE_DB_HOST'),
        'port' => requiredEnv('FE_DB_PORT'),
        'database' => requiredEnv('FE_DB_NAME'),
        'user' => requiredEnv('FE_DB_USER'),
        'password' => requiredEnv('FE_DB_PASSWORD'),
        'charset' => 'utf8',
        'sslVerify' => false,
    ];
}

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || '' === $value) {
        throw new RuntimeException($name . ' is required for this database driver.');
    }

    return $value;
}

function createSchema(Db $db): void
{
    $quote = static function (string $identifier) use ($db): string {
        return $db->getAdapter()->quoteColumn($identifier);
    };

    $contents = $quote('fe_contents');
    $comments = $quote('fe_comments');

    $db->query(sprintf(
        'CREATE TABLE %s (%s INTEGER PRIMARY KEY, %s VARCHAR(32) NOT NULL, '
            . '%s VARCHAR(32) NOT NULL, %s INTEGER NOT NULL, %s VARCHAR(255) NULL, %s INTEGER NOT NULL)',
        $contents,
        $quote('cid'),
        $quote('type'),
        $quote('status'),
        $quote('created'),
        $quote('password'),
        $quote('allowFeed')
    ));

    $db->query(sprintf(
        'CREATE TABLE %s (%s INTEGER PRIMARY KEY, %s INTEGER NOT NULL, %s INTEGER NOT NULL, '
            . '%s VARCHAR(32) NOT NULL, %s VARCHAR(32) NOT NULL, %s INTEGER NOT NULL, '
            . '%s INTEGER NOT NULL, %s VARCHAR(255) NOT NULL)',
        $comments,
        $quote('coid'),
        $quote('cid'),
        $quote('created'),
        $quote('status'),
        $quote('type'),
        $quote('ownerId'),
        $quote('authorId'),
        $quote('text')
    ));
}

function seedVisibilityMatrix(Db $db): void
{
    $contents = [
        [1, 'post', 'publish', 100, null, 1],
        [2, 'post', 'private', 100, null, 1],
        [3, 'post', 'hidden', 100, null, 1],
        [4, 'post', 'publish', 100, 'secret', 1],
        [5, 'post', 'publish', 100, null, 0],
        [6, 'post', 'publish', 2000, null, 1],
        [7, 'page', 'publish', 100, null, 1],
        [8, 'attachment', 'publish', 100, null, 1],
        [9, 'custom', 'publish', 100, null, 1],
    ];

    foreach ($contents as [$cid, $type, $status, $created, $password, $allowFeed]) {
        $db->query($db->insert('table.contents')->rows([
            'cid' => $cid,
            'type' => $type,
            'status' => $status,
            'created' => $created,
            'password' => $password,
            'allowFeed' => $allowFeed,
        ]));
    }

    for ($index = 0; $index < 10; ++$index) {
        insertComment($db, 200 + $index, 1, 'approved', 'comment', 'public-' . $index);
        insertComment($db, 300 + $index, 2, 'approved', 'comment', 'private-' . $index);
    }

    insertComment($db, 400, 1, 'spam', 'comment', 'spam');
    insertComment($db, 401, 9999, 'approved', 'comment', 'orphan');
    insertComment($db, 402, 3, 'approved', 'comment', 'hidden');
    insertComment($db, 403, 4, 'approved', 'comment', 'password');
    insertComment($db, 404, 5, 'approved', 'comment', 'feed-disabled');
    insertComment($db, 405, 6, 'approved', 'comment', 'future');
    insertComment($db, 100, 7, 'approved', 'comment', 'page-comment');
    insertComment($db, 101, 8, 'approved', 'trackback', 'attachment-trackback');
    insertComment($db, 102, 9, 'approved', 'comment', 'custom-parent-comment');
    insertComment($db, 103, 1, 'approved', 'trackback', 'post-trackback');
    insertComment($db, 104, 1, 'approved', 'comment', 'author-comment', 1, 1);
}

function insertComment(
    Db $db,
    int $coid,
    int $cid,
    string $status,
    string $type,
    string $text,
    int $ownerId = 1,
    int $authorId = 0
): void {
    $db->query($db->insert('table.comments')->rows([
        'coid' => $coid,
        'cid' => $cid,
        'created' => $coid,
        'status' => $status,
        'type' => $type,
        'ownerId' => $ownerId,
        'authorId' => $authorId,
        'text' => $text,
    ]));
}

/** @param mixed $expected @param mixed $actual */
function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true)
            . '\nActual: ' . var_export($actual, true));
    }
}

/** @param mixed[] $values */
function assertContains(int $expected, array $values, string $message): void
{
    if (!in_array($expected, $values, true)) {
        throw new RuntimeException($message . '\nActual: ' . var_export($values, true));
    }
}

/** @param mixed[] $values */
function assertNotContains(int $unexpected, array $values, string $message): void
{
    if (in_array($unexpected, $values, true)) {
        throw new RuntimeException($message . '\nActual: ' . var_export($values, true));
    }
}

function assertOrder(string $sql, string $first, string $second): void
{
    $firstPosition = strpos($sql, $first);
    $secondPosition = strpos($sql, $second);

    if (false === $firstPosition || false === $secondPosition || $firstPosition >= $secondPosition) {
        throw new RuntimeException(sprintf('Expected %s before %s in SQL: %s', $first, $second, $sql));
    }
}
