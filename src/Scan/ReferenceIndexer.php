<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

use FreshetUnusedMedia\Detector\CommentDetector;
use FreshetUnusedMedia\Detector\LikePatterns;
use FreshetUnusedMedia\Detector\OptionsDetector;
use FreshetUnusedMedia\Detector\PostContentDetector;
use FreshetUnusedMedia\Detector\PostmetaDetector;
use FreshetUnusedMedia\Detector\TermDescriptionDetector;
use FreshetUnusedMedia\Detector\TermMetaDetector;
use FreshetUnusedMedia\Detector\UserMetaDetector;

defined('ABSPATH') || exit;

/**
 * One pass over the eight searched tables, asking each row which attachments it
 * names — the inversion of `_unusedmedia-1-1.md` §2.
 *
 * **The question turned around.** Today each of *A* attachments asks the corpus
 * *"which rows name me?"*, which is one traversal of the corpus per attachment:
 * O(A × corpus), and on the largest library on hand an extrapolated **17 hours**.
 * Inverted it is one traversal — O(corpus) — and every file is then answered from
 * what that traversal recorded. §2b measured the whole of it at 0.9 s / 9.6 s /
 * 15.4 s on three real libraries.
 *
 * **The one sentence that makes it legal.** The set of attachment ids is known
 * before the pass starts, so *"every integer with a non-digit boundary that is an
 * attachment id"* is a strict **superset** of all nineteen needles
 * `LikePatterns::idConditions()` binds — each of those is that same integer with
 * a delimiter against it. Widening what is *fetched* cannot change an answer,
 * because the thing that decides is the detector's own chain, called here through
 * the very method `find()` calls (`PostmetaDetector::references()` and its seven
 * siblings). Narrowing it would, which is why `BasenameVocabulary` exists: the
 * filename half of the fetch is a substring test, and a suffix index is what
 * keeps it a superset too.
 *
 * **Verification is inline, and that is what holds the evidence lines.** The pass
 * has the row and the attachment in hand at the moment it extracts a candidate,
 * so it runs the chain there and stores the `Reference` the chain returned. The
 * alternative — index the ids and the filenames separately and union them at
 * lookup — gives the same *verdicts* and different *evidence*: every chain asks
 * `containsBasename()` first and returns `url`, so a post carrying both a file
 * URL and a `wp-image-N` class is one reference labelled `url` today and would
 * become two, one of them mislabelled. freshet-290's Acceptance 6 counts
 * evidence lines, and this is how they stay the same by construction rather than
 * by luck.
 *
 * **What the pass does not widen, and the one thing it does.** Every verifier the
 * eight chains can reach has a needle behind it today, with one documented
 * exception: `LikePatterns::hasCaptionAttachmentId()` — the
 * `[caption id="attachment_123"]` shape — which no condition fetches on its own,
 * because the id is written inside a word. freshet-291 declined to add a
 * twentieth SQL condition for it (freshet-D102) on the basis that the inversion
 * catches it for nothing, and it does: `attachment_123` is an integer with a
 * non-digit boundary. So an indexed scan can read a captioned file as *used*
 * where today's reads it as *unused*. That is the safe direction, it is
 * deliberate, and `tests/reference-index.php` asserts it rather than leaving it
 * to be discovered as a parity failure.
 *
 * **A failure leaves the index incomplete, never wrong.** `QueryFailed`
 * propagates out of here untouched. The cursor stays where the last fully
 * committed page left it, `complete` is never set, and nothing answers a file
 * from an incomplete index — so an interrupted pass means *not yet scanned*, not
 * *unused* (freshet-141, one level up).
 */
final class ReferenceIndexer
{
    /** Rows read per query, per §2b's measurement. */
    public const PAGE = 2000;

    /**
     * Attachments whose basenames are primed in one `update_postmeta_cache()`.
     *
     * A hundred rather than five hundred, and the difference is measured: the
     * chunk is the transient high-water of the vocabulary build — a chunk's worth
     * of meta rows, plus the ~35 filenames each attachment yields, all allocated
     * and then given back. A whole pass over a 27,140-attachment library peaks at
     * **115.3 MB at five hundred and 105.3 MB at a hundred**, against a 128 MB
     * `WP_MEMORY_LIMIT`, in the same wall clock either way. It is also the
     * window a row's candidates are primed in — see primeBasenames()
     * (freshet-365).
     */
    private const BASENAME_CHUNK = 100;

    /**
     * Rows verified before this process's caches are given back.
     *
     * More often than once per page, and that is a measurement rather than a
     * guess. Three of the eight chains confirm an ACF field with a
     * `get_post_meta()`, and core answers one of those by caching **every** meta
     * row of that post — so a page of 2,000 postmeta rows belonging to 2,000
     * different posts pulls 2,000 posts' worth of custom fields into memory
     * before the page ends. On a page-builder library that was 54 MB of the
     * pass's peak. Released at this interval instead, the peak drops by a third
     * and the cost is re-reading meta inside a window of 250 rows, which is
     * ordered by meta_id and therefore mostly the same handful of posts.
     */
    private const RELEASE_EVERY = 250;

    /** Candidates verified between two readings of the clock. See index(). */
    private const BUDGET_EVERY = 16;

    /**
     * Attachments whose basenames are remembered at once. See context().
     *
     * A thousand, not a library's worth: one library's 27,140 attachments yield
     * 961,262 filenames between them and holding all of them cost **94 MB**
     * inside a 128 MB `WP_MEMORY_LIMIT` (freshet-365). What a thousand buys is
     * the locality — rows near each other in a keyed cursor name overlapping
     * attachments — and it is dropped whole when it fills rather than evicted one
     * at a time, because tracking an eviction order is the per-entry cost this
     * storage exists to avoid.
     */
    private const BASENAME_MEMO = 1000;

    /**
     * What joins the names in $diskNames.
     *
     * A filename can hold a newline on a POSIX disk; it cannot hold a NUL, which
     * is the one byte no file path may contain.
     */
    private const DISK_NAME_GLUE = "\0";

    /**
     * The attachment id universe, as a set. Rebuilt per request.
     *
     * @var array<int, true>|null
     */
    private static ?array $attachmentIds = null;

    private static ?BasenameVocabulary $vocabulary = null;

    /**
     * Attachment id => its basenames, for the candidates verified lately.
     *
     * @var array<int, string[]>
     */
    private static array $basenames = [];

    /**
     * Attachment id => the basenames only the disk knew, NUL-joined.
     *
     * The one part of an attachment's list that cannot be read again cheaply:
     * size files still on disk that the metadata no longer names, which cost a
     * `scandir()` of their folder. The build reads every attachment in id order,
     * which is the order `SizeSiblings`' four-folder memo is built for, so they
     * are read once there and kept — and the per-candidate read afterwards is
     * metadata only.
     *
     * **Kept joined rather than as arrays, because this is the term that had to
     * shrink.** On a 27,140-attachment library 21,341 of them have such names,
     * 263,609 names and 8.7 MB of bytes — one string each, rather than an array
     * each at a few hundred bytes of overhead on top, and no entry at all for an
     * attachment that has none.
     *
     * @var array<int, string>
     */
    private static array $diskNames = [];

    public function __construct(private readonly ScanState $state)
    {
    }

    /**
     * Carry the pass forward, for at most $budgetSeconds of work.
     *
     * A browser batch hands it the same budget the scan loop gets, because a
     * request has a time limit; the CLI hands it zero, which means *no limit* —
     * there is nothing to time out there, and §2b's figures are for one pass.
     *
     * **The budget starts when the reading does, not when this method is
     * entered.** Before the first row is read the pass has to know the attachment
     * id universe and the filename vocabulary, and on a large library that setup
     * is seconds. Counted against the budget it ate most of one, which is not a
     * slow batch but a stalled pass: measured on a real library, a 20-second
     * budget spent on setup left each request advancing **sixteen candidates** of
     * a row, and the pass went from 434 seconds to over 40 minutes. The batch
     * budget is already half of `max_execution_time` (see the browser scan), so
     * the setup fits in the half that is held back.
     *
     * **Which is why the setup is built where the clock starts and not wherever
     * it is first wanted.** It was lazy until freshet-389: the stamp went down
     * after the first page of rows came back and the vocabulary was built inside
     * the first row's `candidates()`, a few statements later and on the wrong
     * side of the line. The sentence above was the intent; the loop below is now
     * what does it.
     *
     * @return array{complete: bool, read: string, rows: int, entries: int, elapsed: float, total_rows: int, total_entries: int, corpus_rows: int}
     * @throws QueryFailed A read that did not answer; see the class note.
     */
    public function advance(float $budgetSeconds = 0.0, ?callable $onPage = null): array
    {
        $startedAt = microtime(true);
        $requestStartedAt = $startedAt;

        // Set when the first row is actually read — see the note above.
        $workStartedAt = null;
        $reads = $this->reads();
        $cursor = $this->state->indexCursor();

        if ($cursor === null) {
            ReferenceIndex::install();
            ReferenceIndex::truncate();

            // The corpus is counted here and nowhere else, which is why it sits
            // above $workStartedAt with the rest of the setup: the budget is what
            // a request may spend *reading rows*, and this is the once-per-pass
            // measurement that gives the first phase a bar at all (freshet-390,
            // corpusRows()).
            $cursor = $this->state->startIndex($this->corpusRows($reads));
        }

        if ($cursor['complete']) {
            return $this->report($cursor, true, 0, 0, 0.0);
        }

        $position = 0;
        $after = 0;
        $offset = 0;

        if ($cursor['read'] !== '') {
            $position = $this->positionOf($reads, $cursor['read']);

            if ($position === null) {
                // The resumed cursor names a read this version does not have.
                // Starting over is the only honest answer: the rows already
                // written describe a corpus read in a different shape, and
                // carrying on would finish and mark complete an index with a
                // table missing from it.
                ReferenceIndex::install();
                ReferenceIndex::truncate();
                $cursor = $this->state->startIndex($this->corpusRows($reads));
                $position = 0;
            } else {
                $after = $cursor['pk'];
                $offset = $cursor['offset'];
            }
        }

        $rows = 0;
        $entries = 0;

        for (; $position < count($reads); ++$position) {
            $read = $reads[$position];

            // Rows an interrupted page left past the cursor, forgotten before the
            // first of them is written again — a duplicate index row is a
            // duplicate evidence line. Done once per read: with no offset nothing
            // past the cursor is wanted, and it is cleared here rather than after
            // the first fetch so a read that is already exhausted still sheds
            // whatever a since-deleted row left behind.
            $partialRow = $offset > 0;

            if (!$partialRow) {
                ReferenceIndex::forgetAfter($read['read'], $after);
            }

            while (true) {
                $page = $this->page($read, $after);

                if ($page === []) {
                    // An offset into a row the source table no longer has. The
                    // row is gone, so are its references, and so is the offset.
                    if ($partialRow) {
                        ReferenceIndex::forgetAfter($read['read'], $after);
                    }

                    break;
                }

                // With an offset the cursor points *into* the first row of this
                // page and its first $offset candidates are already in, so that
                // one row is the one thing past the cursor that must survive.
                if ($partialRow) {
                    $partialRow = false;

                    ReferenceIndex::forgetAfter($read['read'], (int) $page[0]->frst_pk);
                }

                if ($workStartedAt === null) {
                    // Built here rather than left to the first row's
                    // candidates(), which is where they were built before and
                    // is *inside* the window the next line opens. The docblock
                    // has always said the setup fits in the half of
                    // max_execution_time the budget holds back; lazily, it did
                    // not — the clock started, the vocabulary took most of the
                    // budget, and the row it was built for reported itself out
                    // of time after sixteen candidates (freshet-389).
                    $this->attachmentIds();
                    $this->vocabulary();

                    $workStartedAt = microtime(true);
                }

                $pageRows = 0;
                $pageEntries = 0;
                $sinceRelease = 0;

                foreach ($page as $row) {
                    $pk = (int) $row->frst_pk;
                    $indexed = $this->index($read, $row, $offset, $budgetSeconds, $workStartedAt);

                    $pageEntries += $indexed['entries'];
                    $entries += $indexed['entries'];

                    // The references first, then the cursor past them. A cursor
                    // written ahead of its rows is a piece of the corpus silently
                    // missing from an index that then calls itself complete.
                    ReferenceIndex::commit();

                    if (!$indexed['finished']) {
                        // The budget ran out inside this row. The cursor stays
                        // behind it and records how many of its candidates are in,
                        // so the resume picks the row up rather than repeating it
                        // — see ScanState::indexCursor() for the 370-second row
                        // this exists for.
                        $cursor = $this->state->advanceIndex(
                            $read['read'],
                            $after,
                            $pageRows,
                            $pageEntries,
                            microtime(true) - $startedAt,
                            $indexed['candidates']
                        );

                        return $this->report($cursor, false, $rows + $pageRows, $entries, microtime(true) - $requestStartedAt);
                    }

                    $offset = 0;
                    $after = $pk;
                    ++$pageRows;

                    // Nothing is lost by releasing here: the row's references are
                    // committed and the cursor is about to follow them. See
                    // RELEASE_EVERY.
                    if (++$sinceRelease >= self::RELEASE_EVERY) {
                        $sinceRelease = 0;
                        RuntimeCache::release();
                    }
                }

                RuntimeCache::release();

                $rows += $pageRows;

                $cursor = $this->state->advanceIndex($read['read'], $after, $pageRows, $pageEntries, microtime(true) - $startedAt);
                $startedAt = microtime(true);

                if ($onPage !== null) {
                    $onPage($this->report($cursor, false, $rows, $entries, $startedAt - $requestStartedAt));
                }

                // This request's own clock, not the cursor's running total: the
                // budget is "how long may THIS request spend reading", and a
                // resumed pass carries every earlier request's seconds in the
                // cursor.
                if ($budgetSeconds > 0.0 && $startedAt - $workStartedAt > $budgetSeconds) {
                    return $this->report($cursor, false, $rows, $entries, $startedAt - $requestStartedAt);
                }
            }

            // This read is exhausted. The cursor moves to the next one at zero,
            // so a resume does not re-read a table that is already in.
            $after = 0;
            $offset = 0;
            $next = $reads[$position + 1]['read'] ?? '';

            if ($next !== '') {
                $cursor = $this->state->advanceIndex($next, 0, 0, 0, microtime(true) - $startedAt);
                $startedAt = microtime(true);
            }
        }

        $cursor = $this->state->finishIndex();

        return $this->report($cursor, true, $rows, $entries, microtime(true) - $requestStartedAt);
    }

    /** Drop the per-request structures once a pass is finished with. */
    public static function flush(): void
    {
        self::$attachmentIds = null;
        self::$vocabulary = null;
        self::$basenames = [];
        self::$diskNames = [];
    }

    /**
     * @param array<int, array<string, mixed>> $reads
     */
    private function positionOf(array $reads, string $readId): ?int
    {
        foreach ($reads as $position => $read) {
            if ($read['read'] === $readId) {
                return $position;
            }
        }

        return null;
    }

    /**
     * What a caller is told about the pass, per request or per page.
     *
     * Three pairs of figures and they are easy to mistake for each other, so:
     * `rows` / `entries` are what *this* request got through, `total_rows` /
     * `total_entries` are the pass's running totals off the cursor, and
     * `corpus_rows` is the whole corpus — the denominator `total_rows` is a
     * fraction of (freshet-390). Zero there means a pass resumed from a cursor
     * written before the count existed, which the label and the bar both have a
     * shape for.
     *
     * @return array{complete: bool, read: string, rows: int, entries: int, elapsed: float, total_rows: int, total_entries: int, corpus_rows: int}
     */
    private function report(array $cursor, bool $complete, int $rows, int $entries, float $elapsed): array
    {
        return [
            'complete' => $complete,
            'read' => (string) $cursor['read'],
            'rows' => $rows,
            'entries' => $entries,
            'elapsed' => $elapsed > 0.0 ? $elapsed : (float) $cursor['elapsed'],
            'total_rows' => (int) $cursor['rows'],
            'total_entries' => (int) $cursor['entries'],
            'corpus_rows' => (int) ($cursor['corpus'] ?? 0),
        ];
    }

    /**
     * One page of one read, keyed on that table's primary key.
     *
     * @param array<string, mixed> $read
     * @return array<int, object>
     * @throws QueryFailed
     */
    private function page(array $read, int $after): array
    {
        global $wpdb;

        [$sql, $params] = $this->pageSql($read, $after, self::PAGE);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- composed from the read's own pieces below; every value bound via prepare().
        return Db::rows('index ' . $read['read'], $wpdb->get_results($wpdb->prepare($sql, ...$params)));
    }

    /**
     * How many rows the eight reads hold between them.
     *
     * The first phase's denominator, and the whole of what freshet-390 needed:
     * with it the bar runs off `rows / corpus` from the first request instead of
     * sitting at nothing for the forty minutes a large library's index pass
     * takes.
     *
     * **Once per pass, and that is the design rather than an optimisation.**
     * Eight `COUNT(*)`s inside every request would be a count added to the batch
     * budget freshet-389 exists to defend — on the library that measurement came
     * from the pass is ~106 requests, so a per-request count pays for itself a
     * hundred times over to say the same number. It is taken here, where the
     * cursor is being started, and read off the cursor from then on.
     *
     * **It is the one read in this class that is allowed to fail quietly, and
     * that is deliberate.** Everything else here refuses rather than answers
     * short, because a short answer decides a file. This decides a bar. And the
     * two are not equally cheap to get wrong in the other direction: a filtered
     * `COUNT(*)` is a full scan of the table, so on a library large enough for a
     * host's `max_statement_time` to bite it is the query most likely to be
     * refused — while the keyed, limited page reads the pass actually lives on
     * sail past. Raising here would mean refusing to scan the very libraries
     * this phase exists for, in exchange for a percentage. So a count that does
     * not answer gives no denominator and the pass runs: the bar stays at zero
     * and the line underneath says what it has read, which is what this phase
     * said before it had a bar at all.
     *
     * Zero rather than the seven counts that did answer, for the reason
     * freshet-141 is about one level up: a denominator short by one table is a
     * bar that reaches 100 % with a table still to read, which misinforms more
     * than no bar does.
     *
     * @param array<int, array<string, mixed>> $reads
     */
    private function corpusRows(array $reads): int
    {
        global $wpdb;

        $total = 0;

        try {
            foreach ($reads as $read) {
                [$sql, $params] = $this->countSql($read);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- composed from the read's own pieces below; every value bound via prepare().
                $count = $params === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, ...$params));

                $total += (int) Db::value('index count ' . $read['read'], $count);
            }
        } catch (QueryFailed) {
            // Swallowed, and then cleared — the one place in this plugin that
            // calls forget() away from the top of a scan. Db reads a failure off
            // `$wpdb->last_error`, so a failure left lying here is a failure the
            // pass's first page read would find and report as its own, which
            // would turn a missing percentage into a refused scan.
            Db::forget();

            return 0;
        }

        return $total;
    }

    /**
     * A read's page query: its own columns, its own filters, the cursor last.
     *
     * The cursor condition is appended to the read's filters rather than written
     * into them, which is what lets countSql() below take the same filters
     * without it. Last in the list on purpose — it is the one condition that
     * changes between two requests, and reading the SQL with it at the end is how
     * a person can see which page they are looking at.
     *
     * @param array<string, mixed> $read
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function pageSql(array $read, int $after, int $limit): array
    {
        [$conditions, $params] = $this->conditions($read);

        $conditions[] = "{$read['pk']} > %d";
        $params[] = $after;
        $params[] = $limit;

        return [
            "SELECT {$read['pk']} AS frst_pk, {$read['columns']}
                         FROM {$read['from']}
                         WHERE " . implode("
                           AND ", $conditions) . "
                         ORDER BY {$read['pk']} ASC
                         LIMIT %d",
            $params,
        ];
    }

    /**
     * A read's count query: the same filters, no cursor and no limit.
     *
     * No WHERE at all where the read has no filters, rather than a `1 = 1` to
     * keep the string uniform: `SELECT COUNT(*) FROM wp_termmeta tm` is the shape
     * MySQL answers from an index, and a tautology in front of it is a difference
     * the planner can notice.
     *
     * @param array<string, mixed> $read
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function countSql(array $read): array
    {
        [$conditions, $params] = $this->conditions($read);

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return ["SELECT COUNT(*) FROM {$read['from']}{$where}", $params];
    }

    /**
     * A read's own WHERE conditions and the values they bind.
     *
     * @param array<string, mixed> $read
     * @return array{0: string[], 1: array<int, mixed>}
     */
    private function conditions(array $read): array
    {
        if (!isset($read['where'])) {
            return [[], []];
        }

        [$conditions, $params] = ($read['where'])();

        return [$conditions, $params];
    }

    /**
     * Index one row: every attachment it could be naming, verified.
     *
     * Divisible, and that is not a refinement. One real option row holds 2,388
     * candidate attachment ids and costs 370 seconds of chain, which is more than
     * a request has — so the loop checks the budget as it goes and reports how far
     * it got, and the cursor carries that back (ScanState::indexCursor()).
     * `$skip` is where a resumed row picks up; the candidate order is
     * deterministic, so it is a position and not an estimate.
     *
     * @param array<string, mixed> $read
     * @param float $workStartedAt When this request began reading rows — the
     *        budget runs from there rather than from the start of the request,
     *        for the reason advance() gives.
     * @return array{entries: int, candidates: int, finished: bool}
     */
    private function index(array $read, object $row, int $skip, float $budgetSeconds, float $workStartedAt): array
    {
        $candidates = $this->candidates(
            (string) ($read['idText'])($row),
            (string) ($read['nameText'])($row)
        );

        $pk = (int) $row->frst_pk;
        $written = 0;
        $done = 0;

        $primedTo = $skip;

        // Every candidate below is asked about the *same* row, so the chain's
        // decode of it is hoisted into a one-slot memo in LikePatterns
        // (freshet-366). This is where that slot's usefulness ends: the next
        // row is a different value, and a decoded blob held past the loop that
        // wanted it is tens of megabytes of nothing. The `finally` covers the
        // budget return below as well — a row that ran out of time resumes in
        // another request, which has no memo to inherit.
        try {
            foreach ($candidates as $position => $attachmentId) {
                if ($position < $skip) {
                    ++$done;

                    continue;
                }

                if ($position >= $primedTo) {
                    // One meta read per window of candidates rather than one per
                    // candidate, and this process's caches given back between
                    // windows — so a row naming thousands of attachments holds one
                    // window's meta rather than all of it. The same bargain
                    // RELEASE_EVERY strikes per page, inside a row that is itself
                    // larger than most pages.
                    if ($primedTo > $skip) {
                        RuntimeCache::release();
                    }

                    $primedTo = $this->primeBasenames($candidates, $position);
                }

                foreach (($read['refs'])($this->context($attachmentId), $row) as $reference) {
                    ReferenceIndex::add($attachmentId, $read['read'], $pk, $reference);
                    ++$written;
                }

                ++$done;

                // Checked in groups rather than per candidate: on an ordinary row a
                // candidate is microseconds and the clock would be most of the cost,
                // while on the row this exists for one candidate is 150 ms and a
                // group of sixteen is well inside any budget.
                if (
                    $budgetSeconds > 0.0
                    && $done % self::BUDGET_EVERY === 0
                    && $done < count($candidates)
                    && microtime(true) - $workStartedAt > $budgetSeconds
                ) {
                    return ['entries' => $written, 'candidates' => $done, 'finished' => false];
                }
            }
        } finally {
            LikePatterns::forgetDecoded();
        }

        return ['entries' => $written, 'candidates' => $done, 'finished' => true];
    }

    /**
     * Which attachments one row could be naming.
     *
     * Two halves, matching the two halves of the broad pass they replace. The id
     * half is every maximal run of digits — a maximal run is exactly "an integer
     * with a non-digit boundary" — intersected with the attachment id universe.
     * The filename half is the suffix lookup `BasenameVocabulary` holds.
     *
     * The two texts are given separately because the SQL distinguishes them:
     * post_content binds the id needles and post_excerpt does not, while the
     * basename conditions bind both. Extracting ids from the excerpt as well
     * would be a widening that *invents* references today's scan does not find,
     * and the point of a superset is that it finds the same ones sooner.
     *
     * @return int[] Ascending.
     */
    private function candidates(string $idText, string $nameText): array
    {
        $found = [];
        $universe = $this->attachmentIds();

        if ($universe !== [] && $idText !== '' && preg_match_all('/\d+/', $idText, $matches)) {
            foreach ($matches[0] as $digits) {
                // (int) drops leading zeros, so '0123' offers 123 — wider than
                // any needle admits, which is the safe direction. A run too long
                // to be an id saturates and misses the universe.
                $attachmentId = (int) $digits;

                if (isset($universe[$attachmentId])) {
                    $found[$attachmentId] = true;
                }
            }
        }

        if ($nameText !== '') {
            foreach ($this->vocabulary()->attachmentsNamedIn($nameText) as $attachmentId) {
                $found[$attachmentId] = true;
            }
        }

        $ids = array_keys($found);
        sort($ids);

        return $ids;
    }

    /**
     * The verification context for one candidate.
     *
     * **The basenames are read here and not kept for the whole library, which is
     * freshet-365's whole change.** Every chain asks `containsBasename()` first,
     * so a candidate needs its attachment's full list of names however it was
     * found — and the vocabulary used to hold that list for all of them. On
     * one library that was 961,262 names across 27,140 attachments and **94
     * MB**, on top of the lookup's own 60 MB: 126 MB of structures inside a
     * 128 MB `WP_MEMORY_LIMIT`, which is the fatal that task exists for.
     *
     * So the list is `basenamesFor()`'s again — the same call the live `find()`
     * path makes, which is also why the names arrive unfolded here now;
     * `containsBasename()` folds both sides itself, so nothing about what a pair
     * verifies to changes. Two things keep that cheap: `BASENAME_MEMO`, which
     * holds the attachments met lately, and `primeBasenames()`, which reads a
     * window of a row's candidates in one query rather than one each. The
     * disk half does not come from here at all — see `$diskNames`.
     *
     * The context around them is six fields and is still built per pair:
     * memoising those instead cost **179 MB resident** on this same library.
     */
    /**
     * One meta read for the next window of a row's candidates.
     *
     * Without it `context()` issues one query per candidate, because the memo is
     * bounded and the pass gives core's meta cache back every few hundred rows.
     * Measured on a 34,546-attachment library whose rows name many of them, that
     * was **51,265 queries where the pass needs 5,230**, and the 46,000
     * difference is one round trip each for meta that comes back in one. A
     * row's candidate list is known before the first of them is verified, which
     * is what makes the single read possible.
     *
     * **A window rather than the whole list, because the whole list is not
     * bounded by anything.** One real option row names 2,388 attachments and
     * another names more; priming every candidate of such a row in one call asks
     * core to cache every meta row of thousands of posts, which exhausted a
     * 128 MB limit outright on two of the five libraries measured.
     *
     * @param int[] $candidates
     * @param int   $from Position to prime from.
     * @return int  Position the next window starts at.
     */
    private function primeBasenames(array $candidates, int $from): int
    {
        $wanted = [];

        // candidates() returns an ascending list, so the window is a slice of it.
        foreach (array_slice($candidates, $from, self::BASENAME_CHUNK) as $attachmentId) {
            if (!isset(self::$basenames[$attachmentId])) {
                $wanted[] = $attachmentId;
            }
        }

        // One is the query get_post_meta() would have issued by itself, so there
        // is no threshold worth testing for here — only an empty list to skip.
        if ($wanted !== []) {
            update_postmeta_cache($wanted);
        }

        return $from + self::BASENAME_CHUNK;
    }

    private function context(int $attachmentId): AttachmentContext
    {
        if (!isset(self::$basenames[$attachmentId])) {
            if (count(self::$basenames) >= self::BASENAME_MEMO) {
                self::$basenames = [];
            }

            $names = AttachmentContext::basenamesFor($attachmentId, false);

            // The half the disk had, put back. Without this the list handed to
            // the chain would be *narrower* than the one find() verifies
            // against, which is the one direction that deletes a used file.
            if (isset(self::$diskNames[$attachmentId])) {
                foreach (explode(self::DISK_NAME_GLUE, self::$diskNames[$attachmentId]) as $name) {
                    $names[] = $name;
                }
            }

            self::$basenames[$attachmentId] = $names;
        }

        return AttachmentContext::forIndexedVerification($attachmentId, self::$basenames[$attachmentId]);
    }

    /**
     * The attachment id universe — one query, and the sentence §2a rests on.
     *
     * @return array<int, true>
     * @throws QueryFailed
     */
    private function attachmentIds(): array
    {
        global $wpdb;

        if (self::$attachmentIds !== null) {
            return self::$attachmentIds;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the id universe; 33 ms on 41,452 rows.
        $ids = Db::rows('attachment ids', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'"));

        $universe = [];

        foreach ($ids as $id) {
            $universe[(int) $id] = true;
        }

        // wpdb is still holding the result set this came out of — 34,546 rows of
        // it measured at 17 MB, which is most of what the set itself costs.
        RuntimeCache::release();

        return self::$attachmentIds = $universe;
    }

    /**
     * The filename vocabulary, built once per request.
     *
     * Every attachment's basenames, which is one `basenamesFor()` per row of the
     * library — the postmeta reads primed in chunks, the directory listings
     * memoised per directory by SizeSiblings. It is the setup cost of a pass and
     * it is paid once per request rather than once per page.
     *
     * The names themselves are **not** kept: what the vocabulary stores is one
     * integer per distinct basename, and a candidate's own names are read again
     * in `context()`. That is a second read for the attachments a row actually
     * names, and it is the trade freshet-365 measured — 126 MB of structures down
     * to 19 MB, which is the difference between a pass and a fatal on a library
     * with a third of a million basenames.
     *
     * @throws QueryFailed
     */
    private function vocabulary(): BasenameVocabulary
    {
        if (self::$vocabulary !== null) {
            return self::$vocabulary;
        }

        $vocabulary = new BasenameVocabulary();
        $vocabulary->seedWordPressExtensions();

        foreach (array_chunk(array_keys($this->attachmentIds()), self::BASENAME_CHUNK) as $chunk) {
            // One meta read for the chunk rather than one per attachment — the
            // same priming SharedReads::open() does for a sibling group.
            update_postmeta_cache($chunk);

            foreach ($chunk as $attachmentId) {
                // The disk read happens here and only here — in id order, which
                // is what SizeSiblings' folder memo is sized for. What came out
                // of it is kept so that context() never has to ask again.
                //
                // One call, and it reports its own disk half. This used to be
                // two — the same attachment read again with the disk switched
                // off, purely to array_diff() the one list out of the other —
                // which is a second pass over every metadata row in the library
                // for a difference the first call already knew (freshet-389).
                $fromDisk = [];
                $names = AttachmentContext::basenamesFor($attachmentId, true, $fromDisk);
                $vocabulary->add($attachmentId, $names);

                if ($fromDisk !== []) {
                    self::$diskNames[$attachmentId] = implode(self::DISK_NAME_GLUE, $fromDisk);
                }
            }

            // And given straight back. This is the one loop in the plugin that
            // touches every row of the library in a single phase, and what it
            // primes is the dominant memory term by a factor of four: measured on
            // a 34,546-attachment library, released per chunk the build peaks at
            // 71 MB instead of 132 MB. The vocabulary it is building is kept, of
            // course — that is the point of the loop — and SizeSiblings keeps its
            // directory listings, which are small and expensive to re-read.
            RuntimeCache::release();
        }

        return self::$vocabulary = $vocabulary;
    }

    /**
     * The eight reads, in cursor order.
     *
     * Each one is the detector's own query with its needles taken out and a keyed
     * cursor put in: **the same table, the same filters, the same chain**. Where
     * a filter lives in the detector it is read from there
     * (`PostmetaDetector::EXCLUDED_KEYS`, `OptionsDetector::rowFilter()`,
     * `LikePatterns::revisionCondition()`), never transcribed — a second copy of
     * a filter is how the index and the fallback start disagreeing about which
     * rows exist. The options filter is a whole WHERE fragment rather than a
     * list for that reason — the list left each side to spell the transient
     * conditions out for itself, and what it could not express at all was "every
     * option this plugin writes", which is how this pass came to read its own
     * cursor as a corpus row (freshet-377).
     *
     * **A read is declared in pieces rather than as one SQL string, because two
     * queries are now built from it** (freshet-390): the page the pass reads, and
     * the `COUNT(*)` taken once at the start to give the first phase a
     * denominator. Written as two strings they would be two copies of the same
     * eight filters, which is the thing the paragraph above exists to forbid —
     * and the copy that drifts would be the one nobody reads, silently sizing the
     * corpus against rows the pass never visits. So each read says what it
     * selects, what it reads from, which column the cursor is, and what it
     * filters; pageSql() and countSql() compose from that.
     *
     * `frst_pk` is the cursor column, aliased so the loop above does not need to
     * know which of eight primary keys it is on.
     *
     * `where` is omitted where a read has no filter of its own — `termmeta` is
     * read whole — and that is a real distinction rather than tidiness: its count
     * is then a `COUNT(*)` with no WHERE at all, which is the one shape MySQL
     * answers from the index.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reads(): array
    {
        global $wpdb;

        $postmeta = new PostmetaDetector();
        $postContent = new PostContentDetector();
        $options = new OptionsDetector();
        $termMeta = new TermMetaDetector();
        $termDescription = new TermDescriptionDetector();
        $userMeta = new UserMetaDetector();
        $comment = new CommentDetector();

        return [
            [
                'read' => 'postmeta',
                'pk' => 'pm.meta_id',
                'columns' => 'pm.post_id, pm.meta_key, pm.meta_value,
                                p.post_type, p.post_parent, p.post_status',
                'from' => "{$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id",
                'where' => static function () use ($wpdb): array {
                    [$revisionCondition, $revisionParams] = LikePatterns::revisionCondition('p');
                    $keyPlaceholders = implode(',', array_fill(0, count(PostmetaDetector::EXCLUDED_KEYS), '%s'));

                    return [
                        [
                            $revisionCondition,
                            'pm.meta_key NOT LIKE %s',
                            "pm.meta_key NOT IN ({$keyPlaceholders})",
                        ],
                        array_merge(
                            $revisionParams,
                            [$wpdb->esc_like('_freshet_unusedmedia_') . '%'],
                            PostmetaDetector::EXCLUDED_KEYS
                        ),
                    ];
                },
                'idText' => static fn(object $row): string => (string) $row->meta_value,
                'nameText' => static fn(object $row): string => (string) $row->meta_value,
                'refs' => static fn(AttachmentContext $ctx, object $row): array => $postmeta->references($ctx, $row),
            ],
            [
                'read' => 'post-content',
                'pk' => 'p.ID',
                'columns' => 'p.ID, p.post_parent, p.post_type, p.post_status,
                                p.post_content, p.post_excerpt',
                'from' => "{$wpdb->posts} p",
                'where' => static function (): array {
                    [$revisionCondition, $revisionParams] = LikePatterns::revisionCondition('p');

                    return [
                        [
                            "p.post_type <> 'nav_menu_item'",
                            $revisionCondition,
                            "p.post_status <> 'auto-draft'",
                        ],
                        $revisionParams,
                    ];
                },
                // The id needles are bound on post_content alone; the basename
                // conditions are bound on both columns. The split is the SQL's.
                'idText' => static fn(object $row): string => (string) $row->post_content,
                'nameText' => static fn(object $row): string => (string) $row->post_content . "\n" . (string) $row->post_excerpt,
                'refs' => static fn(AttachmentContext $ctx, object $row): array => $postContent->references($ctx, $row),
            ],
            [
                'read' => 'options',
                'pk' => 'o.option_id',
                'columns' => 'o.option_name, o.option_value',
                'from' => "{$wpdb->options} o",
                'where' => static function (): array {
                    // Including this plugin's own options, which is not a detail
                    // here: the cursor this very pass writes lives in wp_options,
                    // it carries the pass's running reference total, and reading
                    // it made the index depend on its own page size
                    // (freshet-377, OptionsDetector::OWN_PREFIX).
                    [$rowFilter, $rowParams] = OptionsDetector::rowFilter('o');

                    return [[$rowFilter], $rowParams];
                },
                'idText' => static fn(object $row): string => (string) $row->option_value,
                'nameText' => static fn(object $row): string => (string) $row->option_value,
                'refs' => static fn(AttachmentContext $ctx, object $row): array => $options->references($ctx, $row),
            ],
            [
                'read' => 'termmeta',
                'pk' => 'tm.meta_id',
                'columns' => 'tm.term_id, tm.meta_key, tm.meta_value',
                'from' => "{$wpdb->termmeta} tm",
                'idText' => static fn(object $row): string => (string) $row->meta_value,
                'nameText' => static fn(object $row): string => (string) $row->meta_value,
                'refs' => static fn(AttachmentContext $ctx, object $row): array => $termMeta->references($ctx, $row),
            ],
            [
                'read' => 'term-description',
                'pk' => 'tt.term_taxonomy_id',
                'columns' => 'tt.term_id, tt.description',
                'from' => "{$wpdb->term_taxonomy} tt",
                'where' => static fn(): array => [["tt.description <> ''"], []],
                'idText' => static fn(object $row): string => (string) $row->description,
                'nameText' => static fn(object $row): string => (string) $row->description,
                'refs' => static fn(AttachmentContext $ctx, object $row): array => $termDescription->references($ctx, $row),
            ],
            [
                'read' => 'usermeta',
                'pk' => 'um.umeta_id',
                'columns' => 'um.user_id, um.meta_key, um.meta_value',
                'from' => "{$wpdb->usermeta} um",
                'where' => static fn(): array => [
                    [
                        "um.meta_key <> 'session_tokens'",
                        'um.meta_key NOT LIKE %s',
                        'um.meta_key NOT LIKE %s',
                        'um.meta_key NOT LIKE %s',
                    ],
                    [
                        '%' . $wpdb->esc_like('capabilities'),
                        '%' . $wpdb->esc_like('user_level'),
                        '%' . $wpdb->esc_like('user-settings') . '%',
                    ],
                ],
                'idText' => static fn(object $row): string => (string) $row->meta_value,
                'nameText' => static fn(object $row): string => (string) $row->meta_value,
                'refs' => static fn(AttachmentContext $ctx, object $row): array => $userMeta->references($ctx, $row),
            ],
            [
                'read' => 'comment (content)',
                'pk' => 'c.comment_ID',
                'columns' => 'c.comment_ID, c.comment_approved, c.comment_content',
                'from' => "{$wpdb->comments} c",
                'where' => static fn(): array => [["c.comment_approved <> 'spam'"], []],
                'idText' => static fn(object $row): string => (string) $row->comment_content,
                'nameText' => static fn(object $row): string => (string) $row->comment_content,
                'refs' => static fn(AttachmentContext $ctx, object $row): array => $comment->contentReferences($ctx, $row),
            ],
            [
                'read' => 'comment (meta)',
                'pk' => 'cm.meta_id',
                'columns' => 'cm.comment_id, cm.meta_key, cm.meta_value, c.comment_approved',
                'from' => "{$wpdb->commentmeta} cm
                         INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id",
                'where' => static fn(): array => [["c.comment_approved <> 'spam'"], []],
                'idText' => static fn(object $row): string => (string) $row->meta_value,
                'nameText' => static fn(object $row): string => (string) $row->meta_value,
                'refs' => static fn(AttachmentContext $ctx, object $row): array => $comment->metaReferences($ctx, $row),
            ],
        ];
    }
}
