<?php declare(strict_types=1);
/*
 * This file is part of the WP Starter package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WeCodeMore\WpStarter\Config;

use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Symfony\Component\Console\Input\StringInput;
use WeCodeMore\WpStarter\Step\ContentDevStep;
use WeCodeMore\WpStarter\Step\OptionalStep;
use WeCodeMore\WpStarter\Step\Step;
use WeCodeMore\WpStarter\Util\OverwriteHelper;
use WeCodeMore\WpStarter\Util\Paths;
use WeCodeMore\WpStarter\Util\WpVersion;
use WeCodeMore\WpStarter\WpCli;

/**
 * The "$value" that get passed to all the methods comes from JSON, so there's no way to have type
 * safety. Methods will check the type and act accordingly.
 *
 * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration
 */
class Validator
{
    /**
     * @var Paths
     */
    private $paths;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param Paths $paths
     * @param Filesystem $filesystem
     */
    public function __construct(Paths $paths, Filesystem $filesystem)
    {
        $this->paths = $paths;
        $this->filesystem = $filesystem;
    }

    /**
     * Validate the "prevent overwite" setting.
     *
     * It is expected edither:
     * - the word "hard", which means always prevent the overwite of anything;
     * - the word "ask", which means ask the user in case of existing file;
     * - a boolean(-like), which enables or not the overwrite protection.
     *
     * @param string|bool $value
     * @return Result
     */
    public function validateOverwrite($value): Result
    {
        if (is_array($value)) {
            return $this->validateGlobPathArray($value);
        }

        if (trim(strtolower((string)$value)) === OverwriteHelper::HARD) {
            return Result::ok(OverwriteHelper::HARD);
        }

        return $this->validateBoolOrAsk($value);
    }

    /**
     * Validate an array of custom steps to process.
     *
     * It is expected an array of class names implementing step interface.
     * A single class name is accepted and transparently converted to an one item array.
     *
     * @param array $value
     * @return Result
     */
    public function validateSteps($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        is_string($value) and $value = [$value];
        if (!is_array($value)) {
            return Result::errored('Steps config must be an array.');
        }

        $steps = [];
        foreach ($value as $name => $step) {
            if (!is_string($step)) {
                continue;
            }

            $step = trim($step);
            is_subclass_of($step, Step::class, true) and $steps[trim($name)] = $step;
        }

        if (!$steps) {
            return Result::errored('No valid step classes provided.');
        }

        return Result::ok($steps);
    }

    /**
     * Validate custom scripts that are callback executed either before or after each step.
     *
     * It is expected an associative array where the keys are strings that starts with either
     * "pre-" or "post-" followed by the name of target step and values are either a callback or
     * an array of callbacks.
     *
     * @param array $value
     * @return Result
     */
    public function validateScripts($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        if (!is_array($value)) {
            return Result::errored('Scripts config must be either a string or an array.');
        }

        $allScripts = [];

        foreach ($value as $name => $scripts) {
            if (!is_string($name)) {
                continue;
            }

            $name = strtolower($name);
            if (!preg_match('~^(?:pre|post)\-.+$~', $name)) {
                continue;
            }

            if (is_callable($scripts)) {
                $allScripts[$name] = [$scripts];
                continue;
            }

            if (is_array($scripts)) {
                $scripts = array_filter($scripts, 'is_callable');
                $scripts and $allScripts[$name] = $scripts;
            }
        }

        if (!$allScripts) {
            return Result::errored('No valid scripts provided.');
        }

        return Result::ok($allScripts);
    }

    /**
     * Validate the operation to apply for "content dev".
     *
     * WP Starter allows to have plugins, themes and mu-plugins in the same folder of the project
     * itself. For WordPress to be able to recognize those, it is needed they are placed in the
     * wp-content folder, which will also contains 3rd party plugins, themes and mu-plugins pulled
     * via Composer. To keep things separated, and easily managed via Git, WP Starter allows
     * "1st hand" content to be placed in a separate folder of the project and then either
     * symlinked or copied into wp-content folder.
     *
     * This setting tells WP starter what to do: symlink (default) or copy the files.
     * It is accepted:
     * - the word "symlink"
     * - the word "copy"
     * - the word "none", which means do nothing
     * - boolean true, which means default operation, i.e. "symlink"
     * - boolean false, which means do nothing
     *
     * @param string|bool $value
     * @return Result
     */
    public function validateContentDevOperation($value): Result
    {
        if ($value === OptionalStep::ASK) {
            return Result::ok($value);
        }

        is_string($value) and $value = trim(strtolower($value));
        if (in_array($value, ContentDevStep::OPERATIONS, true)) {
            return Result::ok($value);
        }

        $bool = $this->validateBool($value);
        if (!$bool->either(true, false)) {
            return Result::errored(
                "'Dev Content' operation must be either: 'ask', 'symlink', 'copy', true or false."
            );
        }

        return $bool->is(true)
            ? Result::ok(ContentDevStep::OP_SYMLINK)
            : Result::ok(ContentDevStep::OP_NONE);
    }

    /**
     * Validate the WP CLI commands to execute.
     *
     * It is accepted either:
     * - an array of WP CLI commands as they would be run in the terminal;
     * - a string, that is a path to a PHP or JSON file. The file must return (if PHP) or contain
     *   (if JSON) an array of WP CLI commands as they would be run in the terminal.
     *
     * @param string|array $value
     * @return Result
     */
    public function validateWpCliCommands($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        $error = 'WP CLI commands must be either provided as array of commands, or path to a PHP '
            . 'file returning the array, or path to a JSON file containing the array.';

        if (is_string($value)) {
            $path = $this->validatePath($value);

            return $path->notEmpty()
                ? $this->validateWpCliCommandsFileList($path->unwrap())
                : Result::errored($error);
        }

        if (!is_array($value)) {
            return Result::errored($error);
        }

        $commands = array_reduce(
            $value,
            function (array $commands, $command): array {
                $command = $this->validateWpCliCommand($command);
                $command->notEmpty() and $commands[] = $command->unwrap();

                return $commands;
            },
            []
        );

        if (!$commands) {
            return Result::errored($error);
        }

        return Result::ok($commands);
    }

    /**
     * Validate a single WP CLI commands to execute.
     *
     * It is expected a string that is the command as it would be run in the terminal.
     *
     * @param string $value
     * @return Result
     */
    public function validateWpCliCommand($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        if (!is_string($value)) {
            return Result::errored('A WP CLI command must be a string.');
        }

        strpos($value, 'wp ') === 0 and $value = substr($value, 3);

        try {
            $hasPath = preg_match('~^(.+)(\-\-path=[^ ]+)(.+)?$~', $value, $matches);
            $hasPath and $value = trim($matches[1] . $matches[3]);
            $command = (string)new StringInput($value);
        } catch (\Throwable $exception) {
            return Result::error(new \Error($exception->getMessage(), 0, $exception));
        }

        return Result::ok($command);
    }

    /**
     * Validate an array of files to be evaluated by WP CLI commands via `eval_file` command.
     *
     * It is expected an array of file paths, a single path in a string will be transparently
     * converted to an one item array.
     *
     * @param string|array $value
     * @return Result
     */
    public function validateWpCliFiles($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        is_string($value) and $value = [$value];

        if (!is_array($value)) {
            return Result::errored('Files to be evaluated by WP CLI must be provided as array.');
        }

        $files = array_reduce(
            $value,
            function (array $files, $file): array {
                $data = is_array($file) ? WpCli\FileData::fromArray($file) : null;
                (!$data && is_string($file)) and $data = WpCli\FileData::fromPath($file);
                $data->valid() and $files[] = $data;

                return $files;
            },
            []
        );

        if (!$files) {
            return Result::errored('No valid file has been provided to be evaluated by WP CLI.');
        }

        return Result::ok($files);
    }

    /**
     * Validate the path of a file containing WP CLI commands.
     *
     * It is expected a string, that is a path to a PHP or JSON file. The file must return (if PHP)
     * or contain (if JSON) an array of WP CLI commands as they would be run in the terminal.
     *
     * @param string $path
     * @return Result
     */
    public function validateWpCliCommandsFileList($path): Result
    {
        $error = 'WP CLI commands must be either provided as path to a PHP file returning an array '
            .'of commands or as path to a JSON file containing the array.';

        $validPath = $this->validatePath($path);
        if (!$validPath->notEmpty()) {
            return Result::errored($error);
        }

        $fullpath = $validPath->unwrap();
        if (!is_file($fullpath) || !is_readable($fullpath)) {
            return Result::errored("{$error} {$fullpath} is not a file or is not readable.");
        }

        $extension = strtolower((string)pathinfo($fullpath, PATHINFO_EXTENSION));
        $isJson = $extension === 'json';
        if ($extension !== 'php' && !$isJson) {
            return Result::errored($error);
        }

        $data = $isJson
            ? @json_decode(file_get_contents($fullpath), true)
            : @include $fullpath;

        return is_array($data) ? $this->validateWpCliCommands($data) : Result::errored($error);
    }

    /**
     * Validate given value is an instance of WpCli\Executor.
     *
     * The executor is added on runtime by WP Starter itself and not set via configuration, so we
     * can check the type of the actual instance instead of the class.
     *
     * @param $value
     * @return Result
     */
    public function validateCliExecutor($value): Result
    {
        return $value instanceof WpCli\Executor
            ? Result::ok($value)
            : Result::errored('WP CLI executor must be an instance of WpCli\Executor.');
    }

    /**
     * Validate WP version.
     *
     * Checks that given value represnet a valid WP version. It does not check that the version
     * actually exists, just that the value _looks like_ valid version, e.g "4.9.8" or "1.0-alpha".
     * Something like "8.5.1.5" will be considered valid, even if that version does not exist (yet).
     * The returned result in case of success wraps a normalized value in the form "x.x.x".
     *
     * @param string $value
     * @return Result
     */
    public function validateWpVersion($value): Result
    {
        if (!is_string($value) && !is_int($value)) {
            return Result::errored('WP version is expected to be a string or an integer.');
        }

        $normalized = WpVersion::normalize((string)$value);
        if (!$normalized) {
            return Result::errored("{$value} does not represent a valid WP version.");
        }

        return Result::ok($normalized);
    }

    /**
     * Generic validator that checks given value is either: a boolean, a valid URL, a valid path,
     * or the word "ask".
     *
     * @param mixed $value
     * @return Result
     */
    public function validateBoolOrAskOrUrlOrPath($value): Result
    {
        $boolOrAskOrUrl = $this->validateBoolOrAskOrUrl($value);
        if ($boolOrAskOrUrl->notEmpty()) {
            return $boolOrAskOrUrl;
        }

        return $this->validatePath($value);
    }

    /**
     * Generic validator that checks given value is either: a boolean, a valid URL, or the word
     * "ask".
     *
     * @param mixed $value
     * @return Result
     */
    public function validateBoolOrAskOrUrl($value): Result
    {
        $boolOrAsk = $this->validateBoolOrAsk($value);

        if ($boolOrAsk->notEmpty()) {
            return $boolOrAsk;
        }

        if (is_string($value)) {
            return $this->validateUrl(trim(strtolower($value)));
        }

        return Result::errored('Given value must be either a valid URL, a boolean or "ask".');
    }

    /**
     * Generic validator that checks given value is either: a boolean or the word "ask".
     *
     * @param string|bool $value
     * @return Result
     */
    public function validateBoolOrAsk($value): Result
    {
        if ($value === OptionalStep::ASK) {
            return Result::ok(OptionalStep::ASK);
        }

        return $this->validateBool($value);
    }

    /**
     * Generic validator that checks given value is either a valid URL or a valid path.
     *
     * @param string $value
     * @return Result
     */
    public function validateUrlOrPath($value): Result
    {
        $url = $this->validateUrl($value);
        if ($url->notEmpty()) {
            return $url;
        }

        return $this->validatePath($value);
    }

    /**
     * Validate given value is a valid path, i.e. existing file or folder.
     *
     * @param string $value
     * @return Result
     */
    public function validatePath($value): Result
    {
        $path = $this->validateDirName($value)->unwrapOrFallback();

        if (!$path) {
            return Result::errored('Given value must be the path to an existing file or folder.');
        }

        if (is_file($path) || is_dir($path)) {
            return Result::ok($path);
        }

        $fullpath = $this->paths->root("/{$path}");

        return is_file($fullpath) || is_dir($fullpath)
            ? Result::ok($this->filesystem->normalizePath($fullpath))
            : Result::errored('Given value must be the path to an existing file or folder.');
    }

    /**
     * Validate given value is a valid file name.
     *
     * Unfortunately there's no real effective way to check if a string will make a valid file name
     * in PHP. We know some characters ar einvalid, but some combinations of valid characters are
     * invalid names. E.g. spaces and dots are valid characters, but a string made entirely of dots
     * and spaces is not a valid file name. On top of that PHP has issues with UTF-8 names.
     *
     * This method tries its best to return error in case of clearly wrong file names.
     *
     * @param string $value
     * @return Result
     */
    public function validateFileName($value): Result
    {
        if (!is_string($value)) {
            return Result::errored('A file name must be in a string.');
        }

        $normalized = $this->filesystem->normalizePath($value);
        if (!$normalized) {
            return Result::errored("{$value} is not a valid file name.");
        }

        // "prefix" necessary because pathinfo does not work well with entirely non-ASCII names.
        $basename = pathinfo("prefix{$normalized}", PATHINFO_BASENAME);
        if ($basename !== "prefix{$normalized}") {
            return Result::errored("{$value} is not a valid file name.");
        }

        $hasInvalidChars = preg_match(
            '~(\$|\+|\!|\*|\(|\)|,|\{|\}|\||\^|\[|\]|`|"|\>|\<|\#|;|\?|\:|&|\')~',
            $normalized
        );

        if ($hasInvalidChars
            || !str_replace([' ', '.', '~', '%', '@', '='], '', $normalized)
            || substr_count('..', $normalized)
        ) {
            return Result::errored("{$value} is not a valid file name.");
        }

        return Result::ok($normalized);
    }

    /**
     * Validate given value is a valid folder name. No check is done if the folder actually exist.
     *
     * This relies on "validateFileName" method, and so its limitations apply here as well.
     *
     * @param string $value
     * @return Result
     * @see Validator::validateFileName()
     */
    public function validateDirName($value): Result
    {
        if (!is_string($value)) {
            return Result::errored('Folder name must be in a string.');
        }

        $normalized = $this->filesystem->normalizePath($value);
        if (!$normalized) {
            return Result::errored("{$value} is not a valid folder name.");
        }

        $trimmed = $normalized;
        $startWithSlash = $trimmed[0] === '/';
        $startWithSlash and $trimmed = substr($trimmed, 1);
        while (!$startWithSlash && preg_match('~^\.{1,2}/(.+)?~', $trimmed, $relStartMatch)) {
            $trimmed = $relStartMatch[1];
        }

        if ($trimmed === $normalized
            && Platform::isWindows()
            && preg_match('~^[a-z]{1}:/(.+)~i', $normalized, $driveStartMatch)
        ) {
            $trimmed = $driveStartMatch[1];
        }

        foreach (explode('/', $trimmed) as $part) {
            if (!$this->validateFileName($part)->notEmpty()) {
                return Result::errored("{$value} is not a valid folder name.");
            }
        }

        return Result::ok($normalized);
    }

    /**
     * Validate given value is a valid glob path.
     *
     * Similar to validateDirName() and validateFileName() (that are used for the check) the method
     * checks if a path looks like a valid path to be used in glob function in PHP.
     *
     * @param string $value
     * @return Result
     * @see Validator::validateFileName()
     * @see Validator::validateDirName()
     */
    public function validateGlobPath($value): Result
    {
        if (!is_string($value) || !$value) {
            return Result::errored("Glob path must be in a non-empty string.");
        }

        $path1 = str_replace(['*', '?', '[', ']'], ['aa', 'a', '', ''], $value);
        $path2 = str_replace(['*', '?', '[', ']'], ['', 'a', '', ''], $value);

        $valid1 = substr_count($path1, '/') || substr_count($path1, '\\')
            ? $this->validateDirName($path1)
            : $this->validateFileName($path1);

        $valid2 = substr_count($path2, '/') || substr_count($path2, '\\')
            ? $this->validateDirName($path2)
            : $this->validateFileName($path2);

        if ($valid1->notEmpty() && $valid2->notEmpty()) {
            return Result::ok($value);
        }

        return Result::errored("{$value} is not a valid glob path.");
    }

    /**
     * Check if given value is an array of valid paths (existing files or directories).
     *
     * Basically applies validatePath on each item of given array.
     *
     * @param string[] $value
     * @return Result
     * @see Validator::validatePath()
     */
    public function validatePathArray($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        if (!is_array($value)) {
            return Result::errored('Expected an array of paths, given value is not an array.');
        }

        $validated = [];
        foreach ($value as $maybePath) {
            $path = $this->validatePath($maybePath);
            $path->notEmpty() and $validated[] = $path->unwrap();
        }

        if (!$validated) {
            return Result::errored('None of the items of provided array is a valid path.');
        }

        return Result::ok($validated);
    }

    /**
     * Check if given value is an array of valid files or directory names.
     *
     * No check is done on the actual existence of paths. Basically it applies either
     * validateFileName() or validateDirName() (decision made based on the presence of slashes) on
     * each item of given array.
     *
     * @param string[] $value
     * @return Result
     * @see Validator::validateDirName()
     * @see Validator::validateFileName()
     */
    public function validatePathNamesArray($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        if (!is_array($value)) {
            return Result::errored('Expected an array of paths, given value is not an array.');
        }

        $validated = [];
        foreach ($value as $maybePath) {
            $normalized = $this->filesystem->normalizePath($maybePath);
            $validatedPath = substr_count($normalized, '/')
                ? $this->validateDirName($normalized)
                : $this->validateFileName($normalized);

            if ($validatedPath->notEmpty()) {
                $validated[] = $validatedPath->unwrap();
            }
        }

        if (!$validated) {
            return Result::errored('None of the items of provided array represent a valid path.');
        }

        return Result::ok($validated);
    }

    /**
     * Check if given value is an array of valid glob paths.
     *
     * Basically applies validateGlobPath() on each item of given array.
     *
     * @param string[] $value
     * @return Result
     * @see Validator::validateGlobPath()
     */
    public function validateGlobPathArray($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        if (!is_array($value)) {
            return Result::errored('Expected an array of glob paths, given value is not an array.');
        }

        $validated = [];
        foreach ($value as $maybePath) {
            $validatedPath = $this->validateGlobPath($maybePath);
            $validatedPath->notEmpty() and $validated[] = $validatedPath->unwrap();
        }

        if (!$validated) {
            return Result::errored(
                'None of the items of provided array represent a valid glob path.'
            );
        }

        return Result::ok($validated);
    }

    /**
     * Checks given value is  a valid URL.
     *
     * @param string $value
     * @return Result
     */
    public function validateUrl($value): Result
    {
        if (!$value) {
            return Result::none();
        }

        if (!is_string($value)) {
            return Result::errored('URL must be in a string.');
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return Result::errored("{$value} is not a valid URL.");
        }

        return Result::ok($value);
    }

    /**
     * Checks given value is a boolean-like value.
     *
     * Besides of actual booleans, strings "true" / "false", "yes" / "no", "on" / "off" and
     * integres 0 / 1 are all valid input.
     *
     * @param string|int|bool $value
     * @return Result
     */
    public function validateBool($value): Result
    {
        if (in_array($value, [null, ''], true)) {
            return Result::errored('Given value does not represent a boolean.');
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            return Result::errored('Given value does not represent a boolean.');
        }

        return Result::ok($bool);
    }

    /**
     * Checks given value is an integer.
     *
     * Numeric strings and floats are accepted (and casted in the result).
     *
     * @param int|string|float $value
     * @return Result
     */
    public function validateInt($value): Result
    {
        return is_numeric($value)
            ? Result::ok((int)$value)
            : Result::errored('Given value does not represent an integer.');
    }

    /**
     * Checks given value is an array.
     *
     * Because associative array are accepted, and because "raw" values comes form JSON, instances
     * of `stdClass` are accepted and items extracted from there.
     *
     * @param array|\stdClass $value
     * @return Result
     */
    public function validateArray($value): Result
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value)
            ? Result::ok($value)
            : Result::errored('Given value is not, not can be converted to, an array.');
    }
}
