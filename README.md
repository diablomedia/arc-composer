# arc-composer
Composer linter for Arcanist.

This validates composer.json/composer.lock files using `composer validate`.

### Installation
Require this project via composer:
```composer require-dev diablomedia/arc-composer```

Add the load path to your `.arcconfig`:
```json
{
    "load": ["vendor/diablomedia/arc-composer"]
}
```

### Configuration

Add the linter to your `.arclint` file. It is recommended to include both your `composer.json` and `composer.lock` files so that the linter will run if either of these files have changed:
```json
{
    "linters": {
        "composer": {
            "bin": ["/usr/local/bin/composer"],
            "type": "diablomedia-composer",
            "include": [
                "(^composer.json$)",
                "(^composer.lock$)"
            ],
            "strict": "false"
        }
    }
}
```

Enabling the "strict" flag will cause warnings to show up as errors, and cause the `--strict` flag to be passed to the `composer validate` command.

