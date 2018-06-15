<?php

class ComposerLinter extends ArcanistExternalLinter
{
    /**
     * @var array A list of messages to ignore
     */
    protected $ignoreStrings = [
        'See https://getcomposer.org/doc/04-schema.md for details on the schema'
    ];

    /**
     * @var array A list of regex paterns and corresponding properties from composer.json
     */
    protected $regexProperties = [
        '^License.*'                                                                                 => 'license',
        '^If the software is closed-source, you may use "proprietary" as license.$'                  => 'license',
        '^The version field.*'                                                                       => 'version',
        '^The package type.*'                                                                        => 'type',
        '(.*) is required both in require and require-dev.*'                                         => 'require-dev.$1',
        '^The package "(.*)" is pointing to a commit-ref.*'                                          => '$1',
        '^Description for non-existent script "(%s)".*'                                              => '$1',
        '^Name.*does not match the best practice.*'                                                  => 'name',
        '^Defining (autoload.*) with an empty namespace prefix is a bad idea for performance$'       => '$1',
        '^Description for non-existent script "(.*)" found in "scripts-descriptions"$'               => 'scripts-descriptions.$1',
        '^The property (.*) is not defined and the definition does not allow additional properties$' => '$1',

        '^([^ ]+) : .*' => '$1'
    ];


    /**
     * @var array A log of files that have been parsed, to prevent duplicates
     */
    protected $parsedFiles = [];

    /**
     * @var boolean Whether or not composer validate is running in strict mode
     */
    protected $strict = false;

    /**
     * @return string Human-readable linter name.
     */
    public function getInfoName()
    {
        return pht('Composer Dependency Manager');
    }

    /**
     * @return string|null Optionally, return a brief human-readable description.
     */
    public function getInfoDescription()
    {
        return pht('A linter for composer.json & composer.lock files.');
    }

    /**
     * @return string|null Optionally, return a brief human-readable description.
     */
    public function getInstallInstructions()
    {
        return pht('Install composer: https://getcomposer.org/download/');
    }

    /**
     * @return string The linter name
     */
    public function getLinterName()
    {
        return 'COMPOSER';
    }

    /**
     * @return string The linter name to be used in .arclint
     */
    public function getLinterConfigurationName()
    {
        return 'diablomedia-composer';
    }

    /**
     * @return array Linter-specific configuration options that can be set
     */
    public function getLinterConfigurationOptions()
    {
        $options = [
            'strict' => [
                'type' => 'optional string',
                'help' => pht('Run composer validate in strict mode.')
            ],
        ];

        return $options + parent::getLinterConfigurationOptions();
    }

    /**
     * @param string $key The key of the configuration option
     * @param string $value The value of the configuration option
     */
    public function setLinterConfigurationValue($key, $value)
    {
        switch ($key) {
            case 'strict':
                $this->strict = $value === "true" ? true : false;
                break;
        }
    }

    /**
     * @return bool Return true to continue on nonzero error code.
     */
    public function shouldExpectCommandErrors()
    {
        return true;
    }

    /**
     * @return array<string> Mandatory flags
     */
    public function getMandatoryFlags()
    {
        return ['validate', '--ansi'];

        if ($this->strict) {
            $options[] = '--strict';
        }
    }

    /**
     * @return string Default binary to execute.
     */
    public function getDefaultBinary()
    {
        return 'composer';
    }

    /**
     * @param string $path Path to the file being linted
     * @return string The command-ready file argument
     */
    protected function getPathArgumentForLinterFuture($path)
    {
        $path = $this->getJsonFilePath($path);
        return parent::getPathArgumentForLinterFuture($path);
    }

    /**
     * If working on a lock file, get the correlating JSON file
     *
     * @param string $path
     * @return string
     */
    protected function getJsonFilePath($path)
    {
        return preg_replace('/.lock$/', '.json', $path);
    }

    /**
     * Read in the contents of the JSON config file
     *
     * @param string $path
     * @return string
     */
    protected function loadJsonConfig($path)
    {
        $path = $this->getJsonFilePath($path);
        return file_get_contents($path);
    }

    /**
     * @param string $path Path to the file being linted.
     * @param int $err Exit code of the linter.
     * @param string $stdout Stdout of the linter.
     * @param string $stderr Stderr of the linter.
     */
    protected function parseLinterOutput($path, $err, $stdout, $stderr)
    {
        $jsonPath = $this->getJsonFilePath($path);
        if (in_array($jsonPath, $this->parsedFiles)) {
            $message = new ArcanistLintMessage();
            $message->setName('Duplicate run, parsing not required');
            $message->setSeverity(ArcanistLintSeverity::SEVERITY_DISABLED);
            return [$message];
        }

        if ($err === 0) { // successful validation
            return [];
        }

        if (!$stderr) { // error code, but no output to parse
            return false;
        }

        $this->parsedFiles[] = $jsonPath;

        $jsonContents    = '';
        $requireContents = '';
        $requireLineNum  = 0;

        $lines = phutil_split_lines($stderr, $retain_endings = false);

        $messages = [];
        foreach ($lines as $line) {
            // Skip lines that contain ignored strings
            foreach ($this->ignoreStrings as $string) {
                if (strpos($line, $string) !== false) {
                    continue 2;
                }
            }

            // Use ANSI color codes to determine message severity
            switch (substr($line, 0, 8)) {
                case "\e[30;43m": // yellow background
                    $name = 'Composer warning';
                    if ($this->strict) {
                        $severity = ArcanistLintSeverity::SEVERITY_ERROR;
                    } else {
                        $severity = ArcanistLintSeverity::SEVERITY_WARNING;
                    }
                    break;
                case "\e[37;41m": // red background
                    $name     = 'Composer error';
                    $severity = ArcanistLintSeverity::SEVERITY_ERROR;
                    break;
                default:
                    $name     = 'Composer message';
                    $severity = ArcanistLintSeverity::SEVERITY_DISABLED;
                    break;
            }

            // Strip ANSI color codes
            $line = preg_replace('/\e\[[0-9;]+m/', '', $line);

            $message = new ArcanistLintMessage();
            $message->setName($name);
            $message->setPath($this->getJsonFilePath($path));
            $message->setCode($err);
            $message->setDescription($line);
            $message->setSeverity($severity);

            $contents = [];
            foreach ($this->regexProperties as $regex => $replaceKey) {
                // See if regex applies to this line
                if (preg_match('|' . $regex . '|', $line)) {
                    // Run the regex replace on the line to deal with $1 tokens if necessary
                    $replaceKey = preg_replace('|' . $regex . '|', $replaceKey, $line);

                    // Load the JSON config file
                    if (empty($contents)) {
                        $contents = $this->loadJsonConfig($path);
                    }

                    // The JSON key should be formatted like "key":
                    $keyRegex = '("' . $replaceKey . '"\w*:)';

                    // Treat any "." characters as an object property
                    $keyRegex = preg_replace('/\./', '"\w*:).*?("', $keyRegex);

                    // Perform the regex match, storing results in $matches
                    $matches = [];
                    preg_match('|' . $keyRegex . '|s', $contents, $matches, PREG_OFFSET_CAPTURE);

                    if (!empty($matches)) {
                        // Parse the last result in $matches to determine the line number
                        list($before) = str_split($contents, $matches[count($matches) - 1][1]);
                        $lineNum      = substr_count($before, "\n") + 1;
                        $message->setLine($lineNum);
                    } else {
                        echo "arc-composer: Could not find match for regex: " . $keyRegex . "\n";
                    }
                }
                continue;
            }

            $messages[] = $message;
        }

        return $messages;
    }
}
