<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Which column a listing is ordered by, and in which direction.
 *
 * A value object beside ResultFilters, and the division between the two is the
 * whole point: **a filter decides which files are in the set, a sort decides
 * only what order they are read in.** Nothing here may narrow anything. That is
 * why the delete path takes a ResultFilters and never one of these — the button
 * names the filtered set, the loop walks the filtered set, and the order the
 * screen happened to be in when it was pressed changes neither.
 *
 * On a library of tens of thousands of files, putting the biggest or the oldest
 * at the top is the difference between judging a list and guessing at one, which
 * is why this is trust-surface work rather than convenience.
 *
 * **Only what survives the grouping can be ordered on.** The listings page one
 * row per file (FileGroups::subquery), so the columns available are the grouped
 * ones — `fg_key`, the stored path, and `fg_date`, the earliest upload of any of
 * the file's rows — plus size, which no column records and which is therefore
 * measured in PHP exactly as the size filter already measures it. A per-row
 * value like the mime type or the scan timestamp is not a property of the file
 * and is deliberately not offered.
 *
 * **A sort must never reach the WHERE clause.** FileGroups::subquery() puts its
 * filter clauses in HAVING for a reason it states in its own docblock: narrowing
 * rows before they are grouped hides a *used* row from its own group and hands
 * back a file marked unused on half its evidence. Every ordering here is applied
 * to the grouped set, outside the subquery, so there is nothing to reintroduce.
 */
final class ResultSort
{
    public const ARG_ORDERBY = 'orderby';
    public const ARG_ORDER = 'order';

    /** The column keys, which are also the `orderby` values the URL carries. */
    public const BY_FILE = 'file';
    public const BY_DATE = 'date';
    public const BY_SIZE = 'size';

    public const ASC = 'asc';
    public const DESC = 'desc';

    /**
     * Column key => the grouped column it orders by, or null where the answer
     * is not in the database at all.
     *
     * These fragments are the only thing this class hands to SQL, they are
     * literals declared here, and a request value that is not a key of this map
     * becomes no sort at all — so nothing from a URL is ever interpolated into
     * a query.
     */
    private const COLUMNS = [
        self::BY_FILE => 'fg.fg_key',
        self::BY_DATE => 'fg.fg_date',
        self::BY_SIZE => null,
    ];

    /**
     * What one click on an unsorted header does.
     *
     * Not a house style: it is the request itself — *put the biggest or the
     * oldest files at the top*. Size answers that descending and upload date
     * answers it ascending, so each is one click away rather than two. The
     * filename is a name, and a name reads A to Z.
     */
    private const FIRST_CLICK = [
        self::BY_FILE => self::ASC,
        self::BY_DATE => self::ASC,
        self::BY_SIZE => self::DESC,
    ];

    private function __construct(
        public readonly string $column,
        public readonly string $direction,
    ) {
    }

    /** No sort: the listing keeps its natural order, representative id ascending. */
    public static function none(): self
    {
        return new self('', self::ASC);
    }

    /**
     * Validated here rather than trusted, the same way ResultFilters validates
     * its own: an unknown column is not a sort, and anything but "desc" is
     * ascending.
     *
     * @param array<string, mixed> $request Unslashed $_GET or $_POST.
     */
    public static function fromRequest(array $request): self
    {
        $raw = $request[self::ARG_ORDERBY] ?? '';
        $column = is_scalar($raw) ? strtolower(trim((string) $raw)) : '';

        if (!array_key_exists($column, self::COLUMNS)) {
            return self::none();
        }

        $rawOrder = $request[self::ARG_ORDER] ?? '';
        $order = is_scalar($rawOrder) ? strtolower(trim((string) $rawOrder)) : '';

        return new self($column, $order === self::DESC ? self::DESC : self::ASC);
    }

    /** @return string[] The columns a header may offer, in no particular order. */
    public static function columns(): array
    {
        return array_keys(self::COLUMNS);
    }

    public function isActive(): bool
    {
        return $this->column !== '';
    }

    public function isSortedBy(string $column): bool
    {
        return $this->column === $column;
    }

    public function isDescending(): bool
    {
        return $this->direction === self::DESC;
    }

    /**
     * True where the ordering cannot be done by the database, which is size and
     * only size: nothing in the schema records how big a file is. The store
     * reads this to decide whether the page can be taken off a LIMIT or has to
     * be cut from a measured set — see ResultStore::byStatus().
     */
    public function sortsInPhp(): bool
    {
        return $this->column !== '' && self::COLUMNS[$this->column] === null;
    }

    /**
     * The ORDER BY for the grouped set — a literal from COLUMNS, never a value
     * off the request.
     *
     * The representative id is always the last term, and it is not decoration:
     * two files uploaded the same day would otherwise be free to swap places
     * between one page and the next, which on a fifty-row page means a file that
     * appears twice and one that never appears at all.
     */
    public function orderBySql(): string
    {
        $column = $this->column === '' ? null : self::COLUMNS[$this->column];

        if ($column === null) {
            return 'fg.fg_id ASC';
        }

        return $column . ' ' . ($this->isDescending() ? 'DESC' : 'ASC') . ', fg.fg_id ASC';
    }

    /**
     * Files in size order, from a map of representative id => bytes (null where
     * the size could not be read at all).
     *
     * **An unreadable size sorts last in both directions.** It is the same
     * judgement FileSize makes and ResultFilters repeats: an unknown is not a
     * zero. Leading an ascending list with unknowns would present them as the
     * smallest files in the library, and trailing a descending one with them
     * would present them as the largest; putting them after the measured files
     * either way says what is true, which is that nothing is known about them.
     *
     * A file is never dropped for being unmeasurable, because that would make a
     * sort narrow the set. Only a size *filter* removes one.
     *
     * @param array<int, int|null> $bytes
     * @return int[]
     */
    public function orderBytes(array $bytes): array
    {
        if (!$this->sortsInPhp()) {
            return array_keys($bytes);
        }

        $descending = $this->isDescending();

        // Stable since PHP 8.0, which is what keeps equal sizes in the id order
        // the query returned them in rather than an arbitrary one.
        uasort($bytes, static function (?int $a, ?int $b) use ($descending): int {
            if ($a === null || $b === null) {
                return $a === $b ? 0 : ($a === null ? 1 : -1);
            }

            return $descending ? $b <=> $a : $a <=> $b;
        });

        return array_keys($bytes);
    }

    /**
     * The sort as URL arguments — empty when nothing is sorted, so a listing
     * that was never sorted keeps a clean URL.
     *
     * @return array<string, string>
     */
    public function queryArgs(): array
    {
        return $this->isActive()
            ? [self::ARG_ORDERBY => $this->column, self::ARG_ORDER => $this->direction]
            : [];
    }

    /**
     * The arguments a click on one column's header should carry: the same
     * column flips direction, a different one starts at its own first click.
     *
     * @return array<string, string>
     */
    public function linkArgs(string $column): array
    {
        if (!array_key_exists($column, self::COLUMNS)) {
            return [];
        }

        $order = $this->isSortedBy($column)
            ? ($this->isDescending() ? self::ASC : self::DESC)
            : self::FIRST_CLICK[$column];

        return [self::ARG_ORDERBY => $column, self::ARG_ORDER => $order];
    }
}
