<?php

namespace Statamic\UpdateScripts;

use Illuminate\Filesystem\Filesystem;
use Statamic\Console\Composer\Lock;
use Statamic\Console\NullConsole;
use Statamic\Exceptions\ComposerLockFileNotFoundException;
use Statamic\Exceptions\ComposerLockPackageNotFoundException;

abstract class UpdateScript
{
    const BACKUP_PATH = 'storage/statamic/updater/composer.lock.bak';

    protected $console;
    protected $files;
    protected $newLockFile;
    protected $oldLockFile;

    /**
     * Instantiate update script.
     */
    public function __construct($console = null)
    {
        $this->console = $console ?? new NullConsole;
        $this->files = app(Filesystem::class);
        $this->newLockFile = Lock::file()->ensureExists();
        $this->oldLockFile = Lock::file(self::BACKUP_PATH)->ensureExists();
    }

    /**
     * Define the package being updated.
     *
     * @return string
     */
    abstract public function package();

    /**
     * Whether the update should be run.
     *
     * @param string $newVersion
     * @param string $oldVersion
     * @return bool
     */
    abstract public function shouldUpdate($newVersion, $oldVersion);

    /**
     * Perform the update.
     */
    abstract public function update();

    /**
     * Get console command object for outputting messages to console.
     *
     * @return \Illuminate\Console\Command|NullConsole
     */
    public function console()
    {
        return $this->console;
    }

    /**
     * Determine if user is updating to specific version.
     *
     * @param mixed $version
     */
    public function isUpdatingTo($version)
    {
        $oldVersion = $this->oldLockFile->getInstalledVersion($this->package());

        return version_compare($version, $oldVersion, '>');
    }

    /**
     * Register update script with Statamic.
     */
    public static function register()
    {
        if (! app()->has('statamic.update-scripts')) {
            return;
        }

        app('statamic.update-scripts')[] = static::class;
    }

    /**
     * Run all registered update scripts.
     *
     * @param mixed $console
     */
    public static function runAll($console = null)
    {
        $newLockFile = Lock::file();
        $oldLockFile = Lock::file(self::BACKUP_PATH);

        app('statamic.update-scripts')
            ->map(function ($fqcn) use ($console) {
                try {
                    return new $fqcn($console);
                } catch (ComposerLockFileNotFoundException $exception) {
                    return null;
                }
            })
            ->filter()
            ->filter(function ($script) use ($newLockFile, $oldLockFile) {
                try {
                    return $script->shouldUpdate(
                        $newLockFile->getInstalledVersion($script->package()),
                        $oldLockFile->getInstalledVersion($script->package())
                    );
                } catch (ComposerLockPackageNotFoundException $exception) {
                    return false;
                }
            })
            ->each(function ($script) {
                $script->console()->info('Running update script <comment>['.get_class($script).']</comment>');
                $script->update();
            });
    }
}
