<?php

namespace LaravelEnso\Tables\app\Services\Table;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use LaravelEnso\Helpers\app\Classes\Obj;
use Illuminate\Database\Eloquent\Builder;
use LaravelEnso\Tables\app\Exceptions\QueryException;

class Filters
{
    private $request;
    private $query;
    private $columns;
    private $filters;

    public function __construct(Obj $request, Builder $query)
    {
        $this->request = $request;
        $this->query = $query;
        $this->columns = $request->get('columns');
        $this->filters = false;
    }

    public function handle()
    {
        $this->search()
            ->filter()
            ->applyIntervals()
            ->checkParams();

        return $this->filters;
    }

    private function search()
    {
        if (! $this->request->get('meta')->filled('search')) {
            return $this;
        }

        $this->searchArguments()->each(function ($argument) {
            $this->query->where(function ($query) use ($argument) {
                $this->match($query, $argument);
            });
        });

        $this->filters = true;

        return $this;
    }

    private function searchArguments()
    {
        return $this->request->get('meta')->get('searchMode') === 'full'
            ? collect(
                    explode(' ', $this->request->get('meta')->get('search'))
                )->filter()
            : collect($this->request->get('meta')->get('search'));
    }

    private function match($query, $argument)
    {
        $this->columns->each(function ($column) use ($query, $argument) {
            if ($column->get('meta')->get('searchable')) {
                return $this->isNested($column->get('name'))
                    ? $this->whereHasRelation($query, $column->get('data'), $argument)
                    : $query->orWhere(
                        $column->get('data'),
                        $this->request->get('meta')->get('comparisonOperator'),
                        $this->wildcards($argument)
                    );
            }
        });
    }

    private function whereHasRelation($query, $attribute, $argument)
    {
        if (! $this->isNested($attribute)) {
            $query->where(
                $attribute,
                $this->request->get('meta')->get('comparisonOperator'),
                $this->wildcards($argument)
            );

            return;
        }

        $attributes = collect(explode('.', $attribute));

        $query->orWhere(function ($query) use ($attributes, $argument) {
            $query->whereHas($attributes->shift(), function ($query) use ($attributes, $argument) {
                $this->whereHasRelation($query, $attributes->implode('.'), $argument);
            });
        });
    }

    private function wildcards($argument)
    {
        switch ($this->request->get('meta')->get('searchMode')) {
            case 'full':
                return '%'.$argument.'%';
            case 'startsWith':
                return $argument.'%';
            case 'endsWith':
                return '%'.$argument;
            default:
                throw new QueryException(__('Unknown search mode'));
        }
    }

    private function filter()
    {
        if (! $this->request->filled('filters')) {
            return $this;
        }

        $this->query->where(function ($query) {
            $this->parse('filters')->each(function ($filters, $table) use ($query) {
                $filters->each(function ($value, $column) use ($table, $query) {
                    if ($this->filterIsValid($value)) {
                        if ($value instanceof Collection) {
                            $value = $value->toArray();
                        }

                        $query->whereIn($table.'.'.$column, (array) $value);
                        $this->filters = true;
                    }
                });
            });
        });

        return $this;
    }

    private function applyIntervals()
    {
        if (! $this->request->filled('intervals')) {
            return $this;
        }

        $this->query->where(function () {
            $this->parse('intervals')->each(function ($interval, $table) {
                collect($interval)
                    ->each(function ($value, $column) use ($table) {
                        $this->setMinLimit($table, $column, $value)
                            ->setMaxLimit($table, $column, $value);
                    });
            });
        });

        return $this;
    }

    private function checkParams()
    {
        if ($this->request->filled('params')) {
            $this->filters = true;
        }
    }

    private function filterIsValid($value)
    {
        return $value !== null
            && $value !== ''
            && ! ($value instanceof Collection && $value->isEmpty());
    }

    private function setMinLimit($table, $column, $value)
    {
        if ($value->get('min') === null) {
            return $this;
        }

        $dbDateFormat = $value->get('dbDateFormat');

        if ($dbDateFormat) {
            $dateFormat = $value->get('dateFormat')
                ?? config('enso.config.dateFormat');
        }

        $min = $dbDateFormat
            ? Carbon::createFromFormat($dateFormat, $value->get('min'))
                ->format($dbDateFormat)
            : $value->get('min');

        $this->query->where($table.'.'.$column, '>=', $min);

        $this->filters = true;

        return $this;
    }

    private function setMaxLimit($table, $column, $value)
    {
        if ($value->get('max') === null) {
            return $this;
        }

        $dbDateFormat = $value->get('dbDateFormat');

        if ($dbDateFormat) {
            $dateFormat = $value->get('dateFormat')
                ?? config('enso.config.dateFormat');
        }

        $max = $dbDateFormat
            ? Carbon::createFromFormat($dateFormat, $value->get('max'))
                ->format($dbDateFormat)
            : $value->get('max');

        $this->query->where($table.'.'.$column, '<', $max);

        $this->filters = true;

        return $this;
    }

    private function parse($type)
    {
        return is_string($this->request->get($type))
            ? new Obj(json_decode($this->request->get($type), true))
            : $this->request->get($type);
    }

    private function isNested($attribute)
    {
        return Str::contains($attribute, '.');
    }
}
