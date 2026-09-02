<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * What a listing has been narrowed to: upload date, filename, file size.
 *
 * A value object and nothing else — it holds what one request asked for and
 * answers questions about it. The queries live in ResultStore and the controls
 * in ToolsPage, because the narrowing has to reach three places that must never
 * disagree: the rows on screen, the count in the heading, and the set the
 * delete-all loop walks. A filter that changed only the first of those would
 * make the screen more dangerous, not less.
 *
 * Date and filename are query terms — FileGroups turns them into HAVING clauses
 * over the grouped set, so they narrow files rather than rows. Size is not:
 * nothing in the database records how big a file is, so the size test runs in
 * PHP over whatever the other two narrowed the set to. See matchesSize() for
 * what that costs and what it decides about unknowns.
 */
final class ResultFilters
{
    public const ARG_FROM = 'filter_from';
    public const ARG_TO = 'filter_to';
    public const ARG_FILE = 'filter_file';
    public const ARG_MIN = 'filter_min_mb';
    public const ARG_MAX = 'filter_max_mb';

    private const MB = 1048576;

    /** Six decimal places of a megabyte is roughly one byte — fine enough that
     *  rounding to it cannot move a real file across a bound. */
    private const PRECISION = 6;

    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $filename,
        public readonly ?float $minMb,
        public readonly ?float $maxMb,
    ) {
    }

    public static function none(): self
    {
        return new self('', '', '', null, null);
    }

    /**
     * Every value is validated here rather than trusted: an unparseable date or
     * a non-numeric size becomes "no filter", never a query term.
     *
     * @param array<string, mixed> $request Unslashed $_GET or $_POST.
     */
    public static function fromRequest(array $request): self
    {
        return new self(
            self::date($request[self::ARG_FROM] ?? ''),
            self::date($request[self::ARG_TO] ?? ''),
            self::text($request[self::ARG_FILE] ?? ''),
            self::megabytes($request[self::ARG_MIN] ?? ''),
            self::megabytes($request[self::ARG_MAX] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->from !== ''
            || $this->to !== ''
            || $this->filename !== ''
            || $this->hasSizeFilter();
    }

    public function hasSizeFilter(): bool
    {
        return $this->minMb !== null || $this->maxMb !== null;
    }

    public function minBytes(): ?int
    {
        return $this->minMb === null ? null : (int) round($this->minMb * self::MB);
    }

    public function maxBytes(): ?int
    {
        return $this->maxMb === null ? null : (int) round($this->maxMb * self::MB);
    }

    /**
     * The filter as URL arguments — empty when nothing is set, so add_query_arg()
     * on a clean listing stays clean.
     *
     * @return array<string, string>
     */
    public function queryArgs(): array
    {
        return array_filter([
            self::ARG_FROM => $this->from,
            self::ARG_TO => $this->to,
            self::ARG_FILE => $this->filename,
            self::ARG_MIN => $this->minMb === null ? '' : self::number($this->minMb),
            self::ARG_MAX => $this->maxMb === null ? '' : self::number($this->maxMb),
        ], static fn(string $value): bool => $value !== '');
    }

    /**
     * Does one attachment pass the size bounds?
     *
     * Two stat calls per attachment via FileSize::bytes(), which is why the size
     * filter is the one that costs something — ResultStore runs this over the
     * whole date/filename-narrowed set rather than over one page, because the
     * total in the heading and the delete-all set both have to be right.
     *
     * A file whose size cannot be read at all is *excluded* while a size filter
     * is on. That is the safe direction and the deliberate one: an unknown that
     * counted as a match would put a file into a delete set on the strength of a
     * bound nobody could check it against.
     */
    public function matchesSize(int $attachmentId): bool
    {
        if (!$this->hasSizeFilter()) {
            return true;
        }

        $bytes = FileSize::bytes($attachmentId);

        if ($bytes === null) {
            return false;
        }

        $min = $this->minBytes();
        $max = $this->maxBytes();

        return ($min === null || $bytes >= $min) && ($max === null || $bytes <= $max);
    }

    /**
     * The filter in words, for the delete confirmation. A destructive action
     * that names a subset has to say which subset, or the count in the button is
     * the only thing standing between the user and the wrong files.
     */
    public function describe(): string
    {
        $parts = [];

        if ($this->from !== '') {
            /* translators: %s: date in Y-m-d */
            $parts[] = sprintf(__('uploaded on or after %s', 'freshet-unused-media'), $this->from);
        }

        if ($this->to !== '') {
            /* translators: %s: date in Y-m-d */
            $parts[] = sprintf(__('uploaded on or before %s', 'freshet-unused-media'), $this->to);
        }

        if ($this->filename !== '') {
            /* translators: %s: the filename fragment typed into the filter */
            $parts[] = sprintf(__('filename contains "%s"', 'freshet-unused-media'), $this->filename);
        }

        if ($this->minMb !== null) {
            /* translators: %s: a formatted file size */
            $parts[] = sprintf(__('at least %s', 'freshet-unused-media'), size_format((int) $this->minBytes()));
        }

        if ($this->maxMb !== null) {
            /* translators: %s: a formatted file size */
            $parts[] = sprintf(__('at most %s', 'freshet-unused-media'), size_format((int) $this->maxBytes()));
        }

        return implode(', ', $parts);
    }

    private static function date(mixed $raw): string
    {
        $value = trim(is_scalar($raw) ? (string) $raw : '');

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    private static function text(mixed $raw): string
    {
        $value = trim(is_scalar($raw) ? (string) $raw : '');

        return $value === '' ? '' : sanitize_text_field($value);
    }

    private static function megabytes(mixed $raw): ?float
    {
        $value = trim(is_scalar($raw) ? (string) $raw : '');

        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        // Rounded here rather than on the way out, so the bound this object
        // filters by is the one its URL carries. Rounding only for display
        // would let a bound change by a byte every time the filter survived a
        // page link — and a byte is enough to move a file in or out of a
        // delete set.
        $mb = round((float) $value, self::PRECISION);

        return $mb > 0 ? $mb : null;
    }

    /** Round-trips through the URL without picking up a trailing ".0". */
    private static function number(float $mb): string
    {
        return rtrim(rtrim(number_format($mb, self::PRECISION, '.', ''), '0'), '.');
    }
}
