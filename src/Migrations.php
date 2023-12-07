<?php

declare(strict_types=1);

namespace vakata\migrations;

use RuntimeException;
use SplFileInfo;
use vakata\database\DBInterface;

class Migrations
{
    protected DBInterface $db;
    protected string $path;
    protected array $packages = [];

    public function __construct(
        DBInterface $db,
        string $path,
        ?array $features = null,
        ?callable $order = null
    ) {
        $this->db = $db;
        $path = realpath($path);
        if (!$path || !is_dir($path)) {
            throw new RuntimeException('Invalid path');
        }
        $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
        $this->path = $path;

        $migrations = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::KEY_AS_PATHNAME |
                \FilesystemIterator::CURRENT_AS_FILEINFO |
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $object) {
            /**
             * @var SplFileInfo $object
             */
            if ($object->isFile() && strtolower($object->getExtension()) === 'sql') {
                $migrations[] = substr(dirname($object->getRealPath()), strlen($path));
            }
        }
        $migrations = array_unique($migrations);
        sort($migrations);
        $ordered = [];
        if ($features) {
            foreach ($features as $feature) {
                foreach ($migrations as $migration) {
                    if (strpos($migration, $feature) === 0 && !in_array($migration, $ordered)) {
                        $ordered[] = $migration;
                    }
                }
            }
        } else {
            $ordered = $migrations;
        }
        if (isset($order)) {
            $ordered = call_user_func($order, $ordered);
        }
        $this->packages = $ordered;
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
    public function packages(): array
    {
        return $this->packages;
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
        return array_diff($this->packages, $this->status());
    }
    public function reset(): self
    {
        $status = $this->status();
        foreach (array_reverse($this->packages) as $migration) {
            if (in_array($migration, $status) && $this->removable($migration)) {
                $this->uninstall($migration);
                $this->removed($migration);
            }
        }
        foreach ($this->packages as $migration) {
            if ($this->removable($migration)) {
                $this->install($migration);
                $this->applied($migration);
            }
        }
        return $this;
    }
    public function up(): self
    {
        $status = $this->status();
        foreach ($this->packages as $migration) {
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
        foreach (array_reverse($this->packages) as $migration) {
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
        foreach (array_reverse($this->packages) as $migration) {
            if (in_array($migration, $status) && !in_array($migration, $desired)) {
                $this->uninstall($migration);
                $this->removed($migration);
            }
        }
        foreach ($this->packages as $migration) {
            if (in_array($migration, $desired) && !in_array($migration, $status)) {
                $this->install($migration);
                $this->applied($migration);
            }
        }
        return $this;
    }
}
