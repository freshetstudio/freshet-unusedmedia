<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

use FreshetUnusedMedia\Detector\LikePatterns;

defined('ABSPATH') || exit;

/**
 * Which posts still name a size file that has no original.
 *
 * **"Referenced" here means post content**, and that is narrower than what the
 * scan asks of an attachment on purpose. The scan's reference detection is keyed
 * by attachment id end to end — the index, the vocabulary, every detector's id
 * needles — and an orphan has no id. What it does have is a filename, and a size
 * file is put on a page by its URL, which lands in `post_content`. So this asks
 * exactly the question PostContentDetector asks with a basename, in the same
 * rows: the same LIKE builder on `post_content` and `post_excerpt`, the same
 * revision filter (autosaves count, other revisions do not), the same exclusion
 * of auto-drafts and menu items, and the same case-folded `containsBasename()`
 * to verify what the LIKE fetched. A name it finds keeps the file; a reference
 * outside post content does not, and the screen says "post content" rather than
 * "anywhere" for that reason.
 *
 * **Once per scan, after the file pass,** because the file pass is what finds
 * the orphans and a row has no answer until it is complete. Batched by basename
 * rather than one query per file: a chunk's names are OR'd into one pass over the
 * posts table and each fetched row is then verified against every name in the
 * chunk, so a library with 465 of them is twenty passes rather than 465.
 * Measured in freshet-438's Thread. Time-boxed like every other phase, so a host
 * with a ten-second limit finishes it over several requests; the table's own
 * NULLs are the cursor.
 *
 * postsNaming() is also the delete path's live check — the same SQL against the
 * site as it is at that moment, for the one file about to go.
 */
final class OrphanReferences
{
    /** Basenames OR'd into one pass over the posts table. */
    private const CHUNK = 25;

    /**
     * Answer as many unchecked rows as fit in $budget seconds.
     *
     * @return bool Whether every row now has its answer.
     * @throws QueryFailed
     */
    public static function advance(float $budget): bool
    {
        // A scan that began before the table existed never created it and found
        // nothing to record into it, so there is nothing to check.
        if (!OrphanStore::exists()) {
            return true;
        }

        $started = microtime(true);

        do {
            $rows = OrphanStore::unchecked(self::CHUNK);

            if ($rows === []) {
                return true;
            }

            $named = self::postsNaming(array_column($rows, 'file'));

            foreach ($rows as $row) {
                OrphanStore::resolve($row['id'], $named[$row['file']] ?? []);
            }
        } while (microtime(true) - $started < $budget);

        return OrphanStore::unchecked(1) === [];
    }

    /**
     * The posts whose content names each of these basenames, read now.
     *
     * An autosave is attributed to the post it belongs to — the one with a
     * screen — exactly as PostContentDetector attributes it.
     *
     * @param string[] $basenames
     * @return array<string, int[]> Basename => post ids, only for names found.
     * @throws QueryFailed
     */
    public static function postsNaming(array $basenames): array
    {
        global $wpdb;

        $needles = [];

        foreach (array_unique(array_filter(array_map('strval', $basenames))) as $basename) {
            $needles[$basename] = AttachmentContext::settle([$basename]);
        }

        if ($needles === []) {
            return [];
        }

        $all = array_values(array_unique(array_merge(...array_values($needles))));

        [$contentConditions, $contentParams] = LikePatterns::basenameConditions('p.post_content', $all);
        [$excerptConditions, $excerptParams] = LikePatterns::basenameConditions('p.post_excerpt', $all);
        [$revisionCondition, $revisionParams] = LikePatterns::revisionCondition('p');

        $sql = "SELECT p.ID, p.post_parent, p.post_type, p.post_content, p.post_excerpt
                FROM {$wpdb->posts} p
                WHERE p.post_type <> 'nav_menu_item'
                  AND {$revisionCondition}
                  AND p.post_status <> 'auto-draft'
                  AND (" . implode(' OR ', array_merge($contentConditions, $excerptConditions)) . ')';

        $params = array_merge($revisionParams, $contentParams, $excerptParams);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = Db::rows('orphan content check', $wpdb->get_results($wpdb->prepare($sql, ...$params)));

        $found = [];

        foreach ($rows as $row) {
            $row = (object) $row;
            $text = (string) $row->post_content . "\n" . (string) $row->post_excerpt;
            $postId = $row->post_type === 'revision' ? (int) $row->post_parent : (int) $row->ID;

            foreach ($needles as $basename => $forms) {
                if (LikePatterns::containsBasename($text, $forms)) {
                    $found[$basename][$postId] = $postId;
                }
            }
        }

        return array_map(static fn(array $ids): array => array_values($ids), $found);
    }
}
