<?php declare(strict_types = 1);

namespace Modette\ModuleInstaller\Resolving;

use Composer\Json\JsonFile;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Semver\Constraint\EmptyConstraint;
use Modette\Exceptions\Logic\InvalidArgumentException;
use Modette\ModuleInstaller\Configuration\ConfigurationValidator;
use Modette\ModuleInstaller\Configuration\PackageConfiguration;
use Modette\ModuleInstaller\Monorepo\SimulatedPackage;
use Modette\ModuleInstaller\Plugin;
use Modette\ModuleInstaller\Utils\PathResolver;

final class ModuleResolver
{

	/** @var WritableRepositoryInterface */
	private $repository;

	/** @var PathResolver */
	private $pathResolver;

	/** @var ConfigurationValidator */
	private $validator;

	/** @var PackageConfiguration */
	private $rootPackageConfiguration;

	public function __construct(WritableRepositoryInterface $repository, PathResolver $pathResolver, ConfigurationValidator $validator, PackageConfiguration $rootPackageConfiguration)
	{
		$this->repository = $repository;
		$this->pathResolver = $pathResolver;
		$this->validator = $validator;
		$this->rootPackageConfiguration = $rootPackageConfiguration;
	}

	/**
	 * @return PackageConfiguration[]
	 */
	public function getResolvedConfigurations(): array
	{
		/** @var PackageInterface[] $packages */
		$packages = $this->repository->getCanonicalPackages();
		$packages = array_merge($packages, $this->getSimulatedPackages());

		/** @var Module[] $modules */
		$modules = [];

		/** @var string[] $ignored */
		$ignored = [];

		foreach ($packages as $package) {
			if (!$this->isApplicable($package)) {
				if ($package instanceof SimulatedPackage) {
					throw new InvalidArgumentException(sprintf(
						'Cannot set "%s" as a "%s" simulated module. Given package does not have "%s" file.',
						$package->getName(),
						$package->getParentName(),
						Plugin::DEFAULT_FILE_NAME
					));
				}

				continue;
			}

			$modules[$package->getName()] = $module = new Module($this->validator->validateConfiguration($package, Plugin::DEFAULT_FILE_NAME));
			$ignored = array_merge($ignored, $module->getConfiguration()->getIgnoredPackages());
		}

		$ignored = array_merge($ignored, $this->rootPackageConfiguration->getIgnoredPackages());

		foreach ($modules as $module) {
			$module->setDependents(
				$this->packagesToModules(
					$this->flatten($this->getDependents($module->getConfiguration()->getPackage()->getName(), $packages)),
					$modules
				)
			);
		}

		uasort($modules, static function (Module $m1, Module $m2) {
			$d1 = $m1->getDependents();
			$n1 = $m1->getConfiguration()->getPackage()->getName();

			$d2 = $m2->getDependents();
			$n2 = $m2->getConfiguration()->getPackage()->getName();

			// Cyclical dependency, ignore
			if (isset($d1[$n2], $d2[$n1])) {
				return 0;
			}

			if (isset($d1[$n2])) {
				return -1;
			}

			return 1;
		});

		$packageConfigurations = [];

		foreach ($modules as $module) {
			// Skip package configuration if listed in ignored
			if (in_array($module->getConfiguration()->getPackage()->getName(), $ignored, true)) {
				continue;
			}

			$packageConfigurations[] = $module->getConfiguration();
		}

		$packageConfigurations[] = $this->rootPackageConfiguration;

		return $packageConfigurations;
	}

	/**
	 * Returns list of explicitly allowed packages, which are part of monorepo like they were really installed
	 *
	 * @return SimulatedPackage[]
	 */
	public function getSimulatedPackages(): array
	{
		$loader = new ValidatingArrayLoader(new ArrayLoader());

		$parentPackage = $this->rootPackageConfiguration->getPackage();
		$parentPackageName = $parentPackage->getName();
		$parentFullPath = $this->pathResolver->getAbsolutePath($parentPackage);

		$packages = [];

		foreach ($this->rootPackageConfiguration->getSimulatedModules() as $name => $path) {
			// Package exists, simulation not needed
			if ($this->repository->findPackage($name, new EmptyConstraint()) !== null) {
				continue;
			}

			$directoryPath = $this->pathResolver->buildPathFromParts([
				$parentFullPath,
				$this->rootPackageConfiguration->getSchemaPath(),
				$path,
			]);
			$composerFilePath = $this->pathResolver->buildPathFromParts([
				$directoryPath,
				'composer.json',
			]);

			if (!file_exists($composerFilePath)) {
				throw new InvalidArgumentException(sprintf(
					'Package "%s" is not installed and path "%s" is invalid, "%s" not found.',
					$name,
					$path,
					$composerFilePath
				));
			}

			$config = JsonFile::parseJson(file_get_contents($composerFilePath), $composerFilePath) + ['version' => '999.999.999'];

			$package = $loader->load($config, SimulatedPackage::class);
			assert($package instanceof SimulatedPackage);
			$packageName = $package->getName();

			if ($name !== $packageName) {
				throw new InvalidArgumentException(sprintf(
					'Path "%s" contains package "%s" while package "%s" was expected by configuration.',
					$path,
					$packageName,
					$name
				));
			}

			$package->setParentName($parentPackageName);
			$package->setPackageDirectory($directoryPath);
			$packages[] = $package;
		}

		return $packages;
	}

	/**
	 * Filter out packages with no modette.neon and root package (which is handled separately)
	 */
	private function isApplicable(PackageInterface $package): bool
	{
		static $cache = [];
		$name = $package->getName();
		return $cache[$name]
			?? $cache[$name] = (file_exists($this->pathResolver->getSchemaFileFullName($package, Plugin::DEFAULT_FILE_NAME))
				&& $package !== $this->rootPackageConfiguration->getPackage());
	}

	private function getPackageFromLink(Link $link): ?PackageInterface
	{
		static $cache = [];
		$name = $link->getTarget();
		return $cache[$name] ?? $cache[$name] = $this->repository->findPackage($name, new EmptyConstraint());
	}

	/**
	 * @param PackageInterface[] $packages
	 * @param Module[]           $modules
	 * @return Module[]
	 */
	private function packagesToModules(array $packages, array $modules): array
	{
		$result = [];

		foreach ($packages as $package) {
			$name = $package->getName();
			if (isset($modules[$name])) {
				$result[$name] = $modules[$name];
			}
		}

		return $result;
	}

	/**
	 * @param mixed[] $dependents
	 * @return PackageInterface[]
	 */
	private function flatten(array $dependents): array
	{
		$deps = [];

		foreach ($dependents as $dependent) {
			[$package, $children] = $dependent;
			assert($package instanceof PackageInterface);
			assert(is_array($children) || $children === null);

			$name = $package->getName();

			if (!isset($deps[$name])) {
				$deps[$name] = $package;
			}

			if ($children !== null) {
				$deps += $this->flatten($children);
			}
		}

		return $deps;
	}

	/**
	 * Returns a list of packages causing the requested needle packages to be installed.
	 *
	 * @param string             $needle The package name to inspect.
	 * @param PackageInterface[] $packages
	 * @param string[]|null      $packagesFound Used internally when recurring
	 * @return mixed[][] ['packageName' => [$package, $dependents|null]]
	 */
	private function getDependents(string $needle, array $packages, ?array $packagesFound = null): array
	{
		$needle = strtolower($needle);
		$results = [];

		// Initialize the array with the needles before any recursion occurs
		if ($packagesFound === null) {
			$packagesFound = [$needle];
		}

		// Loop over all currently installed packages.
		foreach ($packages as $package) {
			// Skip non-module packages
			if (!$this->isApplicable($package)) {
				continue;
			}

			$links = $package->getRequires();

			// Each loop needs its own "tree" as we want to show the complete dependent set of every needle
			// without warning all the time about finding circular deps
			$packagesInTree = $packagesFound;

			// Replaces are relevant for order
			$links += $package->getReplaces();

			// Only direct dev-requires are relevant and only if they represent modules
			$devLinks = $package->getDevRequires();

			foreach ($devLinks as $key => $link) {
				$resolvedDevPackage = $this->getPackageFromLink($link);

				if ($resolvedDevPackage === null || !$this->isApplicable($resolvedDevPackage)) {
					unset($devLinks[$key]);
				}
			}

			$links += $devLinks;

			// Cross-reference all discovered links to the needles
			foreach ($links as $link) {
				if ($link->getTarget() === $needle) {
					// already resolved this node's dependencies
					if (in_array($link->getSource(), $packagesInTree, true)) {
						$results[$link->getSource()] = [$package, null];
						continue;
					}

					$packagesInTree[] = $link->getSource();
					$dependents = $this->getDependents($link->getSource(), $packages, $packagesInTree);
					$results[$link->getSource()] = [$package, $dependents];
				}
			}
		}

		ksort($results);

		return $results;
	}

}
