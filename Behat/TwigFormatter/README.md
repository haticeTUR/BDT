## BehatFormatter

Behat 3 extension for generating reports from your test results.

### Twig report

![Twig Screenshot](http://i.imgur.com/SlJuhq3.png)

## It's easy!!

* This tool can be installed easily with composer.
* Defining the formatter in the `behat.yml` file
* Modifying the settings in the `behat.yml`file

## Basic usage

Activate the extension by specifying its class in your `behat.yml`:

```json
# behat.yml
default:
  suites:
    ... # All your awesome suites come here
  
  formatters: 
    html:
      output_path: %paths.base%/build/
      
  extensions:
    axenox\BDT\Behat\TwigFormatter\BehatFormatterExtension:
      projectName: BehatTest
      name: html
      renderer: Twig,Behat2
      file_name: Index
      print_args: true
      print_outp: true
      loop_break: true
      show_tags: true
```

## Configuration

* `output_path` - The location where Behat will save the HTML reports. The path defined here is relative to `%paths.base%` and, when omitted, will be default set to the same path. If you require a dynamic component, the variable `%timestamp%` is available to add to your path (e.g.: `%paths_base%/build_%timestamp%` would convert to something like `[paths base]/build_1490114334`).
* `renderer` - The engine that Behat will use for rendering, thus the types of report format Behat should output (multiple report formats are allowed, separate them by commas). Allowed values are:
 * *Behat2* for generating HTML reports like they were generated in Behat 2.
 * *Twig* A new and more modern format based on Twig.
 * *Minimal* An ultra minimal HTML output.
* `file_name` - (Optional) Behat will use a fixed filename and overwrite the same file after each build. By default, Behat will create a new HTML file using a random name (*"renderer name"*_*"date hour"*).
* `projectName` - (Optional) Give your report a page titel.
* `projectDescription` - (Optional) Include a project description on your testreport.
* `projectImage` - (Optional) Include a project image in your testreport.
* `print_args` - (Optional) If set to `true`, Behat will add all arguments for each step to the report. (E.g. Tables).
* `print_outp` - (Optional) If set to `true`, Behat will add the output of each step to the report. (E.g. Exceptions).
* `loop_break` - (Optional) If set to `true`, Behat will add a separating break line after each execution when printing Scenario Outlines.
* `show_tags` - (Optional) If set to `true`, Behat will add tags when printing Scenario's and features.

## Screenshots

To generate screenshots in your testreport you have to change your `FeatureContext.php`:
#### From:
```php
# FeatureContext.php
class FeatureContext extends MinkContext
{
...
}
```

#### To:
```php
# FeatureContext.php
class FeatureContext extends elkan\BehatFormatter\Context\BehatFormatterContext
{
...
}
```

## Todo:
- save html on failures
- save REST responses in testreport
- JSON output - if wanted?
- colors in print stylesheet
- custom footer image/text

## License and Authors

Authors: https://github.com/ElkanRoelen/BehatFormatter/graphs/contributors



General Architectural Flow
Error Handling Layers:
ErrorManager: Central error management class
GlobalExceptionListener: Captures errors during the Behat testing process
FacadeBrowserException: Special browser-based error management
BehatFormatter: Reporting test results

Detailed Component Analysis

1-ErrorManager (axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager) 
Collects and manages errors
Features:
Prevents error duplication
Standardizes errors
Error hashing mechanism
Time-based error filtering
Core Methods:
addError(): Adds a new error
getErrors(): Retrieves all errors
hasErrors(): Checks for the existence of errors
clearErrors(): Clears errors

2-GlobalExceptionListener (axenox\BDT\Behat\Listeners\GlobalExceptionListener)
Captures errors at different stages of the Behat testing process
Listens to events:
afterSuite
afterScenario
afterStep

3-FacadeBrowserException (axenox\BDT\Exceptions\FacadeBrowserException)
A special error class extending RuntimeException
Manages browser-based errors within the Behat test context

4-BehatFormatter (axenox\BDT\Behat\TwigFormatter\Formatter\BehatFormatter)
Converts test results into an HTML report
Creates reports using Twig templates
Collects detailed test statistics:
Successful/failed scenarios
Steps
Timing information

Workflow
Test is initiated
GlobalExceptionListener listens to the test process
If any error occurs, it reports to ErrorManager
ErrorManager standardizes and stores the error
BehatFormatter generates a consolidated report at the end of the test