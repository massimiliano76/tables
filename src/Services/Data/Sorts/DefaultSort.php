<?php

namespace LaravelEnso\Tables\Services\Data\Sorts;

use Illuminate\Database\Eloquent\Builder;
use LaravelEnso\Tables\Services\Data\Config;

class DefaultSort
{
    private Config $config;
    private Builder $query;

    public function __construct(Config $config, Builder $query)
    {
        $this->config = $config;
        $this->query = $query;
    }

    public function handle(): void
    {
        $this->query->orderBy("{$this->table()}.{$this->column()}");
    }

    protected function table(): string
    {
        return $this->query->getModel()->getTable();
    }

    protected function column(): string
    {
        return $this->config->template()->get('defaultSort');
    }
}
