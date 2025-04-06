<?php

declare(strict_types=1);

namespace zsallazar\ffa;

use InvalidArgumentException;
use pocketmine\math\Vector3;
use pocketmine\plugin\DisablePluginException;
use pocketmine\utils\SingletonTrait;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Throwable;
use zsallazar\ffa\command\FFACommand;
use zsallazar\ffa\listener\EntityListener;
use zsallazar\ffa\listener\InventoryListener;
use zsallazar\ffa\listener\PlayerListener;
use pocketmine\plugin\PluginBase;
use function array_key_last;
use function count;
use function explode;
use function gettype;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;

final class FFA extends PluginBase{
    use SingletonTrait {
        setInstance as private;
        reset as private;
    }

    private const float CONFIG_VERSION = 1.0;

    private Settings $settings;
    private KitManager $kitManager;

    protected function onEnable(): void{
        self::setInstance($this);

        $this->checkConfigVersion();

        try {
            $this->loadConfig();
        } catch (Throwable $e) {
            $this->getLogger()->error("Failed to load the config: " . $e->getMessage());
            throw new DisablePluginException();
        }

        $this->kitManager = new KitManager();

        $server = $this->getServer();
        $pluginManager = $server->getPluginManager();
        $commandMap = $server->getCommandMap();

        $pluginManager->registerEvents(new PlayerListener(), $this);
        $pluginManager->registerEvents(new EntityListener(), $this);
        $pluginManager->registerEvents(new InventoryListener(), $this);

        $commandMap->register($this->getName(), new FFACommand());
    }

    public function getSettings(): Settings{ return $this->settings; }

    public function getKitManager(): KitManager{ return $this->kitManager; }

    private function checkConfigVersion(): void{
        if ($this->getConfig()->get("config-version", 0) !== self::CONFIG_VERSION) {
            $this->getLogger()->warning("Your config is outdated! Creating a new one...");

            $oldConfigPath = Path::join($this->getDataFolder(), "config-old.yml");
            $newConfigPath = Path::join($this->getDataFolder(), "config.yml");

            $filesystem = new Filesystem();
            try {
                $filesystem->rename($newConfigPath, $oldConfigPath);
            } catch (IOException $e) {
                $this->getLogger()->critical("Failed to create a new config: " . $e->getMessage());
                throw new DisablePluginException();
            }

            $this->reloadConfig();
        }
    }

    /**
     * @throws InvalidArgumentException when the settings are invalid
     */
    private function loadConfig(): void{
        $config = $this->getConfig();
        $throwError = function(string $setting, string $type, mixed $value): void{
            throw new InvalidArgumentException("Setting '$setting' is invalid. '" . array_key_last(explode(".", $setting)) . "' must be $type, got " . $value . "(" . gettype($value) . ")");
        };

        $prefix = $config->getNested("settings.prefix");
        if (!is_string($prefix)) {
            $throwError("settings.prefix", "a string", $prefix);
        }

        $scoreboard = $config->getNested("settings.scoreboard");
        if (!is_bool($scoreboard)) {
            $throwError("settings.scoreboard", "a boolean", $scoreboard);
        }

        $combatTime = $config->getNested("settings.combat-time");
        if (!is_int($combatTime) || $combatTime < 0) {
            $throwError("settings.combat-time", "a non-negative integer", $combatTime);
        }

        $safeZoneCenter = $config->getNested("settings.safe-zone.center");
        if (
            !is_string($safeZoneCenter) ||
            count($pos = explode(";", $safeZoneCenter)) !== 3 ||
            !is_numeric($pos[0]) ||
            !is_numeric($pos[1]) ||
            !is_numeric($pos[2])
        ) {
            $throwError("settings.safe-zone.center", "3 numbers that are seperated by a semicolon (x;y;z)", $safeZoneCenter);
        }

        $safeZoneRadius = $config->getNested("settings.safe-zone.radius");
        if (!is_int($safeZoneRadius) || $safeZoneRadius < 0) {
            $throwError("settings.safe-zone.radius", "a non-negative integer", $safeZoneRadius);
        }

        //TODO: Find a better solution for this
        $circleCenterPos = explode(";", $safeZoneCenter); // @phpstan-ignore argument.type
        $this->settings = new Settings(
            $prefix,
            $scoreboard,
            $combatTime,
            new Vector3((float)$circleCenterPos[0], (float)$circleCenterPos[1], (float)$circleCenterPos[2]),
            $safeZoneRadius
        );
    }
}