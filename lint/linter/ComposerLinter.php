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

    public function getInfoName()
    {
        return pht('Composer Dependency Manager');
    }

    public function getInfoDescription()
    {
        return pht('A linter for composer.json & composer.lock files.');
    }

    public function getInstallInstructions()
    {
        return pht('Install composer: https://getcomposer.org/download/');
    }

    public function getLinterName()
    {
        return 'COMPOSER';
    }

    public function getLinterConfigurationName()
    {
        return 'diablomedia-composer';
    }

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

    public function setLinterConfigurationValue($key, $value)
    {
        switch ($key) {
            case 'strict':
                $this->strict = $value === "true" ? true : false;
                return;

            default:
                return parent::setLinterConfigurationValue($key, $value);
        }
    }

    public function shouldExpectCommandErrors()
    {
        return true;
    }

    public function getMandatoryFlags()
    {
        return ['validate', '--ansi'];

        if ($this->strict) {
            $options[] = '--strict';
        }
    }

    public function getDefaultBinary()
    {
        return 'composer';
    }

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

    protected function parseLinterOutput($path, $err, $stdout, $stderr)
    {
        $jsonPath = $this->getJsonFilePath($path);
        if (in_array($jsonPath, $this->parsedFiles)) {
            $message = new ArcanistLintMessage();
            $message->setName('Duplicate run, parsing not required');
            $message->setSeverity(ArcanistLintSeverity::SEVERITY_DISABLED);
            return [$message];
        }

        $this->parsedFiles[] = $jsonPath;

        $jsonContents    = '';
        $requireContents = '';
        $requireLineNum  = 0;

        if (!$stderr) {
            return false;
        }

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
                    var_dump($keyRegex);

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
