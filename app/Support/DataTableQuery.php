<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Shared read-only pagination/sorting with per-listing allowlists. */
class DataTableQuery
{
    public static function options(Request $request, array $columns, int $default = 15): array
    {
        $values = $request->validate([
            'per_page' => ['sometimes', 'integer', Rule::in([10, 15, 25, 50, 100])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in($columns)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        return ['per_page' => (int) ($values['per_page'] ?? $default),
            'sort' => $values['sort'] ?? null, 'direction' => $values['direction'] ?? 'asc'];
    }

    public static function query(Builder $query, Request $request, array $options): LengthAwarePaginator
    {
        if ($options['sort']) $query->reorder($options['sort'], $options['direction'])->orderBy('id');
        $page = min(max(1, (int) $request->input('page', 1)), max(1, (int) ceil((clone $query)->count() / $options['per_page'])));
        return $query->paginate($options['per_page'], ['*'], 'page', $page)->withQueryString();
    }

    public static function collection(Collection $rows, Request $request, array $options, array $numeric = []): LengthAwarePaginator
    {
        if ($field = $options['sort']) {
            $rows = $rows->sort(function ($a, $b) use ($field, $options, $numeric) {
                $compare = in_array($field, $numeric, true)
                    ? ((float) data_get($a, $field) <=> (float) data_get($b, $field))
                    : strcasecmp((string) data_get($a, $field), (string) data_get($b, $field));
                return $compare === 0 ? strcmp((string) data_get($a, 'id'), (string) data_get($b, 'id'))
                    : ($options['direction'] === 'desc' ? -$compare : $compare);
            })->values();
        }
        $page = min(max(1, (int) $request->input('page', 1)), max(1, (int) ceil($rows->count() / $options['per_page'])));
        return (new LengthAwarePaginator($rows->forPage($page, $options['per_page'])->values(), $rows->count(),
            $options['per_page'], $page, ['path' => $request->url()]))->withQueryString();
    }
}
