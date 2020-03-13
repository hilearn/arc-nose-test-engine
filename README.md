Here is an exaxmple of `.arcconfig` file that can be used in other projects.
```json
{
  "phabricator.uri" : "https://example_url",
  "unit.engine": "DotnetUnitTestEngine",
  "unit.engine.paths": [
    "Example.Tests"
  ],
  "load": [
    "arclib"
  ]
}
```

And an example of `.arclint` file.
```json
{
  "linters": {
    "bandit": {
      "type": "bandit",
      "include": "(\\.py$)",
      "exclude": "(\\./.venv$)"
    }
  }
}
```