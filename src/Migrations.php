<?php

declare(strict_types=1);

namespace helpers;

use vakata\database\DBInterface;

class Migrations
{
    protected DBInterface $db;
    protected string $path;
    protected array $features;

    public function __construct(
        DBInterface $db,
        string $path,
        array $features = []
    ) {
        $this->db = $db;
        $this->path = rtrim($path, '/') . DIRECTORY_SEPARATOR . $db->driverName() . DIRECTORY_SEPARATOR;
        if (!is_dir($this->path) || !is_dir($this->path . 'base')) {
            throw new \Exception('Unsupported database');
        }
        $this->features = $features;
    }

    protected function execute(string $sql): void
    {
        $sql = str_replace("\r", '', $sql);
        $sql = preg_replace('(\n+)', "\n", $sql) ?: throw new \RuntimeException();
        $sql = explode(";\n", $sql . "\n");
        foreach (array_filter(array_map("trim", $sql)) as $q) {
            $q = preg_replace('(--.*\n)', '', $q) ?: throw new \RuntimeException();
            $this->db->query($q);
        }
    }
    protected function install(string $migration): void
    {
        $schema = $this->path . $migration . DIRECTORY_SEPARATOR . 'schema.sql';
        if (is_file($schema)) {
            $this->execute(file_get_contents($schema) ?: throw new \RuntimeException());
        }
        $data = $this->path . $migration . DIRECTORY_SEPARATOR . 'data.sql';
        if (is_file($data)) {
            $sql = file_get_contents($data) ?: throw new \RuntimeException();
            $this->execute($sql);
        }
    }
    protected function uninstall(string $migration): void
    {
        $migration = $this->path . $migration . DIRECTORY_SEPARATOR . 'uninstall.sql';
        if (is_file($migration)) {
            $this->execute(file_get_contents($migration) ?: throw new \RuntimeException());
        }
    }
    protected function status(): array
    {
        try {
            return $this->db->all("SELECT package FROM migrations WHERE removed IS NULL ORDER BY migration");
        } catch (\Exception $e) {
            // the migrations table may not exist
            return [];
        }
    }
    protected function applied(string $migration): void
    {
        $this->db->query(
            "INSERT INTO migrations (package, installed) VALUES (?, ?)",
            [ $migration, date('Y-m-d H:i:s') ]
        );
    }
    protected function removed(string $migration): void
    {
        try {
            $this->db->query(
                "UPDATE migrations SET removed = ? WHERE package = ? AND removed IS NULL",
                [ date('Y-m-d H:i:s'), $migration ]
            );
        } catch (\Exception $ignore) {
            // when removing the core package the migrations table is removed
        }
    }
    protected function collect(): array
    {
        $migrations = [];
        foreach (scandir($this->path . 'base/_core/') ?: [] as $migration) {
            if (
                !in_array($migration, ['.', '..']) &&
                is_dir($this->path . 'base/_core/' . $migration)
            ) {
                $migrations[] = 'base/_core/' . $migration;
            }
        }
        foreach (scandir($this->path . 'base') ?: [] as $item) {
            if (
                !in_array($item, ['.', '..']) &&
                is_dir($this->path . 'base/' . $item) &&
                (isset($this->features[strtoupper($item)]) && $this->features[strtoupper($item)])
            ) {
                foreach (scandir($this->path . 'base/' . $item) ?: [] as $migration) {
                    if (
                        !in_array($migration, ['.', '..']) &&
                        is_dir($this->path . 'base/' . $item . '/' . $migration)
                    ) {
                        $migrations[] = 'base/' . $item . '/' . $migration;
                    }
                }
            }
        }
        foreach (scandir($this->path . 'app/_core/') ?: [] as $migration) {
            if (
                !in_array($migration, ['.', '..']) &&
                is_dir($this->path . 'app/_core/' . $migration)
            ) {
                $migrations[] = 'app/_core/' . $migration;
            }
        }
        foreach (scandir($this->path . 'app') ?: [] as $item) {
            if (
                !in_array($item, ['.', '..']) &&
                is_dir($this->path . 'app/' . $item) &&
                (isset($this->features[strtoupper($item)]) && $this->features[strtoupper($item)])
            ) {
                foreach (scandir($this->path . 'app/' . $item) ?: [] as $migration) {
                    if (
                        !in_array($migration, ['.', '..']) &&
                        is_dir($this->path . 'app/' . $item . '/' . $migration)
                    ) {
                        $migrations[] = 'app/' . $item . '/' . $migration;
                    }
                }
            }
        }
        return $migrations;
    }
    protected function removable(string $migration): bool
    {
        return is_file($this->path . $migration . DIRECTORY_SEPARATOR . 'uninstall.sql');
    }
    public function current(): array
    {
        return $this->status();
    }
    public function waiting(): array
    {
        return array_diff($this->collect(), $this->status());
    }
    public function reset(): self
    {
        $status = $this->status();
        $migrations = $this->collect();
        foreach (array_reverse($migrations) as $migration) {
            if (in_array($migration, $status) && $this->removable($migration)) {
                $this->uninstall($migration);
                $this->removed($migration);
            }
        }
        foreach ($migrations as $migration) {
            $parts = explode('/', $migration);
            if ($parts[0] === 'base') {
                if ($this->removable($migration)) {
                    if (
                        $parts[1] === '_core' ||
                        (isset($this->features[strtoupper($parts[1])]) && $this->features[strtoupper($parts[1])])
                    ) {
                        $this->install($migration);
                        $this->applied($migration);
                    }
                }
            }
        }
        return $this;
    }
    public function up(): self
    {
        $status = $this->status();
        foreach ($this->collect() as $migration) {
            if (!in_array($migration, $status)) {
                $this->install($migration);
                $this->applied($migration);
            }
        }
        return $this;
    }
    public function down(): self
    {
        $status = $this->status();
        foreach (array_reverse($this->collect()) as $migration) {
            if (in_array($migration, $status)) {
                $this->uninstall($migration);
                $this->removed($migration);
            }
        }
        return $this;
    }
    public function to(array $desired): self
    {
        $status = $this->status();
        foreach (array_reverse($this->collect()) as $migration) {
            if (in_array($migration, $status) && !in_array($migration, $desired)) {
                $this->uninstall($migration);
                $this->removed($migration);
            }
        }
        foreach ($this->collect() as $migration) {
            if (in_array($migration, $desired) && !in_array($migration, $status)) {
                $this->install($migration);
                $this->applied($migration);
            }
        }
        return $this;
    }
}
