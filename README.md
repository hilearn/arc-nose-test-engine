Here is an exaxmple of `.arcconfig` file that can be used in other projects.
For `dotnet` project
```json
{
  "phabricator.uri" : "https://example_url",
  "unit.engine": "DotnetUnitTestEngine",
  "unit.engine.csharp.paths": [
    "Example.Tests"
  ],
  "load": [
    "arclib"
  ]
}
```
For `python` project
```json
{
  "phabricator.uri" : "https://example_url",
  "unit.engine": "PythonMultiTestEngine",
  "unit.engine.python.environment": {
    "VAR_NAME": "value_in_env"
  },
  "unit.engine.python.roots": {
    "root_dir": ["tests_path/"]
  },
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
