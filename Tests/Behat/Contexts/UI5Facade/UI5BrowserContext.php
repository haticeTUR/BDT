<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\TwigFormatter\Context\BehatFormatterContext;
use Behat\Behat\Context\Context;
use Behat\Mink\Element\NodeElement;
use Behat\MinkExtension\Context\MinkContext;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\Assert;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Testwork\Tester\Result\TestResult;
use Behat\Behat\Tester\Exception\PendingException;
use Behat\Step\Then;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Gherkin\Node\TableNode;

/**
 * Test steps available for the OpenUI5 facade
 * 
 * UI5BrowserContext class provides test steps for OpenUI5 facade testing
 * Each scenario gets its own context instance
 * 
 * Every scenario gets its own context instance.
 * You can also pass arbitrary arguments to the
 * context constructor through behat.yml.
 * 
 */
class UI5BrowserContext extends BehatFormatterContext implements Context
{
    private $browser;
    private $scenarioName;

    private $workbench = null;
    // private $screenshotDir; // Directory path for storing screenshots


    // private $lastScreenshot = null;  // Add this property at the top of the class with other properties


    /**
     * Constructor initializes screenshot directory path
     * Creates the screenshot directory if it doesn't exist
     */
    public function __construct()
    {
        $this->workbench = new Workbench();
        $this->workbench->start();
    }

    /**
     * Dynamically determines workbench root path
     * Traverses up from current directory until finding vendor directory
     * @return string Path to workbench root
     */
    private function getWorkbenchPath(): string
    {
        return $this->getWorkbench()->getInstallationPath();
    }


    /**
     * Captures scenario name before execution
     * @param BeforeScenarioScope $scope Behat scenario scope
     */
    public function beforeScenario(BeforeScenarioScope $scope)
    {
        $this->scenarioName = $scope->getScenario()->getTitle();

        // Start XHR monitoring
        if ($this->browser) {
            $this->browser->initializeXHRMonitoring();
            echo "\nXHR monitoring initialized for scenario: " . $this->scenarioName . "\n";
        }
    }

    /**
     * Cleans up visual debugging highlights from UI5 elements before each test step
     * 
     * @BeforeStep
     */
    public function clearWidgetHighlights(BeforeStepScope $scope): void
    {
        // Verify both session and browser wrapper are initialized
        // This prevents errors when running steps before browser setup
        try {
            // Execute cleanup script in browser context
            if ($this->getSession() && $this->browser) {
                // Execute cleanup script in browser context
                $this->getSession()->executeScript('
                    // Check if cleanup function exists from previous highlight operations
                    if (window.clearHighlight) {
                        // Find all elements with outline style (indicating highlights)
                        document.querySelectorAll("[style*=\'outline\']").forEach(el => {
                            // Only clean elements that have debug labels
                            // This prevents affecting legitimate UI styles
                            if (el._debugLabel) {
                                window.clearHighlight(el);
                            }
                        });
                    }
                ');
            }
        } catch (\Exception $e) {
            // Session henüz başlatılmamış olabilir, sessizce devam et
            return;
        }
    }

    private function attachErrorDetailsToResult(AfterScenarioScope $scope, string $message): void
    {
        try {
            $reflection = new \ReflectionObject($scope->getTestResult());
            $messageProperty = $reflection->getProperty('message');
            $messageProperty->setAccessible(true);
            $messageProperty->setValue($scope->getTestResult(), $message);
        } catch (\Exception $e) {
            echo "\nWarning: Could not attach error details to report: " . $e->getMessage();
        }
    }

    /** @AfterStep */
    public function takeScreenshotAfterFailedStep(AfterStepScope $scope)
    {
        if (!$scope->getTestResult()->isPassed()) {
            // $this->takeScreenshot();
        }
    }


    /**
     * @Then /^I should see the page$/
     */
    public function iShouldSeeThePage()
    {
        $page = $this->getSession()->getPage();
        Assert::assertNotNull($page->getContent(), 'Page content is empty');
    }


    /**
     * Summary of focusStack
     * @var \Behat\Mink\Element\NodeElement[]
     */
    private $focusStack = [];

    /**
     * Log in to a URL with a specific role and locale
     * 
     * Examples:
     * - Given I log in to page "exface.core.logs.html" as "Support"
     * - Given I log in to page "exface.core.logs.html" as "Support, Debugger"
     * - Given I log in to page "exface.core.logs.html" as "Support" with locale "de_DE"
     * - Given I log in to page "exface.core.logs.html" as "exface.Core.SUPERUSER"
     * 
     * @Given I log in to the page :url
     * @Given I log in to the page :url as :userRole
     * @Given I log in to the page :url as :userRole with locale :locale
     */
    public function iLogInToPage(string $url, string $userRoles = null, string $userLocale = null)
    {
        try {
            // Setup the user and get the required login data
            $userRolesArray = $this->splitArgument($userRoles);
            $loginFields = UI5Browser::setupUser($this->getWorkbench(), $userRolesArray, $userLocale);
            $tabCaption = $loginFields['_tab'];
            unset($loginFields['_tab']);
            $btnCaption = $loginFields['_button'];
            unset($loginFields['_button']);

            // Go to the page
            $this->iVisitPage($url);

            // Find the correct authenticator tab. Keep retrying for 5
            $this->getBrowser()->goToTab($tabCaption, null, 5);

            // Fill out the login form
            foreach ($loginFields as $caption => $value) {
                $input = $this->getBrowser()->findInputByCaption($caption);
                Assert::assertNotNull($input, 'Cannot find login field "' . $caption . '"');
                $input->setValue($value);
            }

            // Clear XHR logs before login
            $this->getBrowser()->clearXHRLog();

            // Press login button
            $loginButton = $this->getBrowser()->findButtonByCaption($btnCaption);
            Assert::assertNotNull($loginButton, 'Cannot find login button "' . $btnCaption . '"');
            $loginButton->click();

            // Wait for login completion
            // TODO centralize waiting!!!
            sleep(3);
            $this->getBrowser()->waitWhileAppBusy(60);
            $this->getBrowser()->waitForAjaxFinished(30);

            // Check for failed requests
            // TODO move to UI5Browser - it is too complicated
            $failedRequests = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.requests) {
                    return window.exfXHRLog.requests.filter(function(req) {
                        return req.status >= 300;
                    }).map(function(req) {
                        return {
                            url: req.url || "unknown",
                            status: req.status || 0,
                            response: req.response || "No response"
                        };
                    });
                }
                return [];
            ');

            if (!empty($failedRequests)) {
                $errorMsg = "Failed requests detected during login:\n";
                foreach ($failedRequests as $req) {
                    $errorMsg .= sprintf(
                        "URL: %s\nStatus: %s\nResponse: %s\n\n",
                        $req['url'],
                        $req['status'],
                        substr($req['response'], 0, 500)
                    );
                }
                throw new \Exception($errorMsg);
            }

            // Check authentication status
            // TODO make it simple to check, if we have an error status code. No need to write these
            // get-if sequences every time. Also do not throw an exception, but 
            // TODO move it further up. Why wait if bad status code?
            $statusCode = $this->getSession()->getStatusCode();
            if ($statusCode >= 400) {
                throw new \Exception("Login failed: Unauthorized (401)");
            }
        } catch (\Exception $e) {
            echo "Debug - Login error: " . $e->getMessage() . "\n";
            $this->getWorkbench()->getLogger()->logException($e);
            throw $e; // throw error
        }
    }

    /**
     * Summary of iVisitPage
     * 
     * @Given I visit page :url
     * 
     * @param string $url
     * @return void
     */
    public function iVisitPage(string $url): void
    {
        // Navigate to page
        $this->visitPath('/' . $url);
        echo "Debug - New page is loading...\n";

        $this->browser = new UI5Browser($this->getWorkbench(), $this->getSession(), $url);
        return;
    }

    /**
     * Helper function to get widget location description
     */
    private function getWidgetLocation(NodeElement $widget): string
    {
        $parent = $widget->find('xpath', './ancestor::*[contains(@class, "sapMPage") or contains(@class, "sapMPopup")][1]');
        if ($parent) {
            $title = $parent->find('css', '.sapMTitle');
            if ($title) {
                return "in " . trim($title->getText());
            }
        }
        return "in page";
    }

    /**
     * @Then I see :number widget of type ":widgetType"
     * @Then I see :number widgets of type ":widgetType"
     * @Then I see :number widget of type ":widgetType" with ":objectAlias"
     * @Then I see :number widgets of type ":widgetType" with ":objectAlias"
     */
    public function iSeeWidgets(int $number, string $widgetType, string $objectAlias = null): void
    {
        $maxRetries = 5;
        $retryCount = 0;
        $widgetNodes = [];

        // Initialize the visual debugging helpers
        $this->getSession()->executeScript(<<<'JS'
        window.highlightElement = function(element, color, label) {
            const rect = element.getBoundingClientRect();
            
            // Create label with location context
            const debugLabel = document.createElement('div');
            debugLabel.style.cssText = 'position: absolute;' +
                'background: ' + color + ';' +
                'color: white;' +
                'padding: 4px 8px;' +
                'border-radius: 4px;' +
                'font-size: 12px;' +
                'z-index: 9999;' +
                'pointer-events: none;';
            debugLabel.textContent = label;
            
            // Position above the element
            debugLabel.style.top = (rect.top + window.scrollY - 25) + 'px';
            debugLabel.style.left = rect.left + 'px';
            
            // Add highlight to element
            element.style.outline = '2px solid ' + color;
            element.style.backgroundColor = color + '33';
            
            // Store info for cleanup
            element._debugLabel = debugLabel;
            element._originalStyles = {
                outline: element.style.outline,
                background: element.style.backgroundColor
            };
            
            document.body.appendChild(debugLabel);
        };
    
        window.clearHighlight = function(element) {
            if (element._debugLabel) {
                element._debugLabel.remove();
                element.style.outline = element._originalStyles.outline;
                element.style.backgroundColor = element._originalStyles.background;
                delete element._debugLabel;
                delete element._originalStyles;
            }
        };
    JS
        );

        while ($retryCount < $maxRetries) {
            try {
                $this->getBrowser()->waitForPageIsFullyLoaded(10);
                $this->getBrowser()->waitWhileAppBusy(30);
                $this->getBrowser()->waitForAjaxFinished(30);

                $this->getBrowser()->setObjectAlias($objectAlias);
                $widgetNodes = $this->getBrowser()->findWidgets($widgetType, null, 5);

                // Clear any existing highlights
                $this->getSession()->executeScript('
                    document.querySelectorAll("[style*=\'outline\']").forEach(el => {
                        if (el._debugLabel) window.clearHighlight(el);
                    });
                ');

                // Colors for different widget types
                $colors = [
                    'DataTable' => '#4CAF50',
                    'Dialog' => '#2196F3',
                    'Input' => '#FF9800'
                ];
                $defaultColor = '#9C27B0';

                foreach ($widgetNodes as $index => $node) {
                    $color = $colors[$widgetType] ?? $defaultColor;

                    // Get widget location
                    $location = $this->getWidgetLocation($node);

                    // Get content sample and clean it up
                    $contentSample = $node->getText();
                    $contentSample = preg_replace('/\s+/', ' ', $contentSample);
                    $contentSample = trim($contentSample);

                    // Add visual highlight with location
                    $this->getSession()->executeScript(
                        sprintf(
                            'window.highlightElement(document.querySelector("#%s"), "%s", "%s #%d %s");',
                            $node->getAttribute('id'),
                            $color,
                            $widgetType,
                            $index + 1,
                            $location
                        )
                    );

                    // Log detailed widget information
                    echo "\nWidget #" . ($index + 1) . " Details:";
                    echo "\n - ID: " . $node->getAttribute('id');
                    echo "\n - Type: " . $widgetType;
                    echo "\n - Location: " . $location;
                    echo "\n - Classes: " . $node->getAttribute('class');
                    echo "\n - Content Preview: " . mb_substr($contentSample, 0, 100) . "...\n";
                }

                // Check if we found enough widgets
                if ($widgetType === 'DataTable') {
                    if (count($widgetNodes) >= $number) {
                        break;
                    }
                } else {
                    if (count($widgetNodes) === $number) {
                        break;
                    }
                }

                // echo sprintf(
                //     "\nTry %d: Found %d '%s' widget(s)%s (expected: %d)",
                //     $retryCount + 1,
                //     count($widgetNodes),
                //     $widgetType,
                //     $objectAlias ? " containing '$objectAlias'" : '',
                //     $number
                // );

                $retryCount++;
                sleep(5);

            } catch (\Exception $e) {
                $retryCount++;
                sleep(5);
                continue;
            }
        }

        // Assert correct number of widgets found
        if ($widgetType === 'DataTable') {
            Assert::assertGreaterThanOrEqual(
                $number,
                count($widgetNodes),
                sprintf(
                    "Expected at least %d '%s' widget(s), Found: %d\nURL: %s",
                    $number,
                    $widgetType,
                    count($widgetNodes),
                    $this->getSession()->getCurrentUrl()
                )
            );
        } else {
            Assert::assertEquals(
                $number,
                count($widgetNodes),
                sprintf(
                    "Expected exactly %d '%s' widget(s), Found: %d\nURL: %s",
                    $number,
                    $widgetType,
                    count($widgetNodes),
                    $this->getSession()->getCurrentUrl()
                )
            );
        }

        if (count($widgetNodes) === 1) {
            $this->focus($widgetNodes[0]);
        }
    }
    //v1

    private function getCurrentUrlWithHash(): string
    {
        return $this->getSession()->evaluateScript('
            return window.location.href + 
            (window.location.hash ? "" : "#" + window.location.hash);
        ');
    }



    /**
     * @Then It has :number widget of type ":widgetType"
     */
    public function itHasWidgetsOfType(int $number, string $widgetType): void
    {
        // Get the currently focused node
        $focusedNode = $this->getFocusedNode();
        Assert::assertNotNull(
            $focusedNode,
            'No widget has focus right now - cannot use steps like "It has..."'
        );

        // Wait for UI5 components to fully load
        $this->getBrowser()->waitForPageIsFullyLoaded(10);
        $this->getBrowser()->waitWhileAppBusy(30);
        $this->getBrowser()->waitForAjaxFinished(10);

        // Find the main form container
        $form = $focusedNode->find('css', '.sapUiForm') ?? $focusedNode;

        // // Debugging information about the form
        // echo "\nSearching in context:\n";
        // echo "Classes: " . $form->getAttribute('class') . "\n";
        // echo "Content: " . $form->getText() . "\n";

        // Find widgets of the specified type within the form
        $widgetNodes = $this->getBrowser()->findWidgets($widgetType, $form);

        // // Debugging results
        // echo "\nDebug - Widget Search Results:\n";
        // echo "Looking for widget type: " . $widgetType . "\n";
        // echo "Expected count: " . $number . "\n";
        // echo "Found count: " . count($widgetNodes) . "\n";

        if (count($widgetNodes) === 0) {
            // If no widgets found, list potential input elements for debugging 
            // Some UI5 input components may be inside .sapMInputBaseContentWrapper, but in the test scenario, 
            //    only the actual input fields need to be found
            // echo "\nAll potential input elements:\n";
            $allElements = $form->findAll('css', '.sapMInputBase:not(.sapMInputBaseContentWrapper)');

            foreach ($allElements as $index => $element) {
                // echo "Element #" . ($index + 1) . ":\n";
                // echo "ID: " . ($element->getAttribute('id') ?? 'no id') . "\n";
                // echo "Classes: " . ($element->getAttribute('class') ?? 'no class') . "\n";
                $inner = $element->find('css', '.sapMInputBaseInner');
                // echo "Has inner input: " . ($inner ? 'yes' : 'no') . "\n";
                // echo "Visible: " . ($element->isVisible() ? 'yes' : 'no') . "\n";
                // echo "---\n";
            }
        }
        // Assert the expected number of widgets
        Assert::assertEquals(
            $number,
            count($widgetNodes),
            sprintf(
                "Expected %d widgets of type '%s', found %d",
                $number,
                $widgetType,
                count($widgetNodes)
            )
        );
    }


    /**
     * @Then I fill the following fields:
     */
    public function iFillTheFollowingFields(TableNode $fields): void
    {
        foreach ($fields->getHash() as $row) {
            // Find input by caption
            $widget = $this->getBrowser()->findInputByCaption($row['widget_name']);
            Assert::assertNotNull(
                $widget,
                sprintf('Cannot find input widget "%s"', $row['widget_name'])
            );

            // Set value and wait for any UI reactions
            $widget->setValue($row['value']);

            // Wait for potential UI updates
            $this->getBrowser()->waitWhileAppBusy(5);
            $this->getBrowser()->waitForAjaxFinished(5);
        }
    }

    /**
     * @Then It has filters: :filterList
     */
    public function itHasFilters(string $filterList): void
    {
        $filters = array_map('trim', explode(',', $filterList));

        // Input containerları bul
        $inputContainers = $this->getBrowser()->getPage()->findAll('css', '.sapUiVlt.exfw-Filter');
        $foundFilters = [];

        foreach ($inputContainers as $container) {
            $label = $container->find('css', '.sapMLabel bdi');
            if ($label) {
                $foundFilters[] = trim($label->getText());
            }
        }

        foreach ($filters as $filter) {
            Assert::assertTrue(
                in_array($filter, $foundFilters),
                sprintf(
                    'Filter "%s" not found. Available filters: %s',
                    $filter,
                    implode(', ', $foundFilters)
                )
            );
        }
    }


    /**
     * Filter input handling for UI5 applications
     * Supports both standard input fields and special UI5 components like ComboBox
     * 
     * @When I enter :value in filter :filterName
     * 
     * @param string $value The value to enter/select in the filter
     * @param string $filterName The name/label of the filter field
     * @throws \RuntimeException if filter field cannot be found or interaction fails
     */
    public function iEnterInFilter(string $value, string $filterName): void
    {
        // Wait for any pending UI5 operations to complete
        $this->getBrowser()->waitWhileAppBusy(5);
        $this->getBrowser()->waitForAjaxFinished(5);

        // Find all filter containers in the page
        $inputContainers = $this->getBrowser()->getPage()->findAll('css', '.sapUiVlt.exfw-Filter');
        $targetInput = null;

        // Iterate through containers to find matching filter
        foreach ($inputContainers as $container) {
            $label = $container->find('css', '.sapMLabel bdi');
            if ($label && trim($label->getText()) === $filterName) {
                // First check if this is a ComboBox/MultiComboBox component
                $comboBox = $container->find('css', '.sapMComboBoxBase, .sapMMultiComboBox');
                if ($comboBox) {
                    // Handle ComboBox type input
                    $this->handleComboBoxInput($comboBox, $value);
                    return;
                }

                // Check for Select type input
                $select = $container->find('css', '.sapMSelect');
                if ($select) {
                    // Handle Select type input
                    $this->handleSelectInput($select, $value);
                    return;
                }

                // Standard handling for regular input fields
                $targetInput = $container->find('css', 'input.sapMInputBaseInner');
                break;
            }
        }

        // If a standard input field was found, set its value
        if ($targetInput) {
            $targetInput->setValue($value);
            $this->getBrowser()->waitWhileAppBusy(5);
            $this->getBrowser()->waitForAjaxFinished(5);
        } else {
            throw new \RuntimeException("Could not find input element for filter: {$filterName}");
        }
    }


    /**
     * Handles input for ComboBox and MultiComboBox components
     * 
     * @param NodeElement $comboBox The ComboBox element to interact with
     * @param string $value The value to select
     * @throws \RuntimeException if value cannot be selected
     */
    private function handleComboBoxInput(NodeElement $comboBox, string $value): void
    {
        // Find and click the dropdown arrow
        $arrow = $comboBox->find('css', '.sapMInputBaseIconContainer');
        if (!$arrow) {
            throw new \RuntimeException("Could not find ComboBox dropdown arrow");
        }

        // Open the dropdown
        $arrow->click();
        $this->getBrowser()->waitWhileAppBusy(2);

        // Find and select the matching item from dropdown list
        $item = $this->getBrowser()->getPage()->find(
            'css',
            ".sapMSelectList li:contains('{$value}'), " .
            ".sapMComboBoxItem:contains('{$value}'), " .
            ".sapMMultiComboBoxItem:contains('{$value}')"
        );

        if (!$item) {
            throw new \RuntimeException("Could not find option '{$value}' in ComboBox list");
        }

        $item->click();
        $this->getBrowser()->waitWhileAppBusy(5);
        $this->getBrowser()->waitForAjaxFinished(5);
    }


    /**
     * Handles input for Select components
     * 
     * @param NodeElement $select The Select element to interact with
     * @param string $value The value to select
     * @throws \RuntimeException if value cannot be selected
     */
    private function handleSelectInput(NodeElement $select, string $value): void
    {
        // Find and click the select to open options
        $select->click();
        $this->getBrowser()->waitWhileAppBusy(2);

        // Find and select the matching item
        $item = $this->getBrowser()->getPage()->find('css', ".sapMSelectList li:contains('{$value}')");

        if (!$item) {
            throw new \RuntimeException("Could not find option '{$value}' in Select list");
        }

        $item->click();
        $this->getBrowser()->waitWhileAppBusy(5);
        $this->getBrowser()->waitForAjaxFinished(5);
    }

    protected function waitForElement($element, $timeout = 30)
    {
        return $this->getSession()->wait(
            $timeout * 1000,
            "jQuery('" . $element . "').length > 0"
        );
    }

    /**
     * UI'nin güncellenmesini bekler (Özel fonksiyon)
     */
    public function waitForTableUpdate()
    {
        $this->getSession()->wait(5000, "document.querySelectorAll('.sapUiTableRow').length > 0");
    }



    public function getTableContents(NodeElement $table, string $columnName): array
    {
        $contents = [];

        // Find column index
        $headerCells = $table->findAll('css', '.sapUiTableHeaderCell');
        $columnIndex = null;

        foreach ($headerCells as $index => $cell) {
            $label = $cell->find('css', '.sapUiTableCellInner label, .sapUiTableCellInner .sapMLabel');
            if ($label && trim($label->getText()) === $columnName) {
                $columnIndex = $index;
                break;
            }
        }

        if ($columnIndex !== null) {
            $rows = $table->findAll('css', '.sapUiTableCtrl tr[data-sap-ui-rowindex]');
            foreach ($rows as $row) {
                $cells = $row->findAll('css', '.sapUiTableCell');
                if (isset($cells[$columnIndex])) {
                    $textElement = $cells[$columnIndex]->find('css', '.sapMText');
                    if ($textElement) {
                        $contents[] = trim($textElement->getText());
                    }
                }
            }
        }

        return $contents;
    }


    /**
     * @Then I see ":text" in column ":columnName"
     */
    public function iSeeInColumn(string $text, string $columnName): void
    {
        try {
            // TODO wait AFTER operations, not before them. Otherwise we would need to add such
            // waiting blocks EVERYWHERE. Also make ONE main waitForPendingOperations() method,
            // that will call all the detailed methods except for calling multiple methods
            // everywhere.

            // Wait for any pending operations
            $this->getBrowser()->waitForAjaxFinished(10);
            $this->getBrowser()->waitWhileAppBusy(10);

            // Find UI5 DataTable
            $dataTables = $this->getBrowser()->findWidgets('DataTable');
            Assert::assertNotEmpty($dataTables, 'No DataTable found on page');
            $table = $dataTables[0];

            // Find column index
            $headers = $table->findAll('css', '.sapUiTableHeaderCell label');
            $columnIndex = null;

            foreach ($headers as $index => $header) {
                if (trim($header->getText()) === $columnName) {
                    $columnIndex = $index;
                    break;
                }
            }

            Assert::assertNotNull($columnIndex, "Column '$columnName' not found");

            // Get all rows
            $rows = $table->findAll('css', '.sapUiTableRow');
            $found = false;

            foreach ($rows as $row) {
                $cells = $row->findAll('css', '.sapUiTableCell');
                if (empty($cells) || !isset($cells[$columnIndex])) {
                    continue;
                }

                $cell = $cells[$columnIndex];

                // Detect UI5 component type based on cell content
                if ($progressBar = $cell->find('css', '.sapMPI')) {
                    // ProgressBar type component
                    $textElements = $progressBar->findAll('css', '.sapMPIText');
                    foreach ($textElements as $textElement) {
                        if (trim($textElement->getText()) === $text) {
                            $found = true;
                            break 2;
                        }
                    }
                } elseif ($objectStatus = $cell->find('css', '.sapMObjStatus')) {
                    // ObjectStatus type component
                    $textElement = $objectStatus->find('css', '.sapMObjStatusText');
                    if ($textElement && trim($textElement->getText()) === $text) {
                        $found = true;
                        break;
                    }
                } elseif ($tokenizer = $cell->find('css', '.sapMTokenizer')) {
                    // Token/MultiInput type component
                    $tokens = $tokenizer->findAll('css', '.sapMToken');
                    foreach ($tokens as $token) {
                        $textElement = $token->find('css', '.sapMTokenText');
                        if ($textElement && trim($textElement->getText()) === $text) {
                            $found = true;
                            break 2;
                        }
                    }
                } else {
                    // Standard text components (including links, labels etc)
                    $textElements = $cell->findAll('css', '.sapMText, .sapMLnk, .sapMLabel');
                    foreach ($textElements as $textElement) {
                        if (trim($textElement->getText()) === $text) {
                            $found = true;
                            break 2;
                        }
                    }
                }
            }

            // Detailed debug information if text not found
            if (!$found) {
                echo "\nSearching for: '$text' in column '$columnName'";
                echo "\nColumn index: $columnIndex";
                echo "\nFound rows: " . count($rows);

                // Sample of actual values
                echo "\nSample values in column:";
                $sampleCount = 0;
                foreach ($rows as $row) {
                    if ($sampleCount >= 3)
                        break;

                    $cells = $row->findAll('css', '.sapUiTableCell');
                    if (isset($cells[$columnIndex])) {
                        $cell = $cells[$columnIndex];
                        echo "\nCell HTML classes: " . $cell->getAttribute('class');

                        // Show content based on component type
                        if ($cell->find('css', '.sapMPI')) {
                            $texts = $cell->findAll('css', '.sapMPIText');
                            foreach ($texts as $t) {
                                echo "\n - ProgressBar text: '" . trim($t->getText()) . "'";
                            }
                        } elseif ($cell->find('css', '.sapMObjStatus')) {
                            $statusText = $cell->find('css', '.sapMObjStatusText');
                            echo "\n - ObjectStatus text: '" . ($statusText ? trim($statusText->getText()) : 'none') . "'";
                        } elseif ($cell->find('css', '.sapMTokenizer')) {
                            $tokens = $cell->findAll('css', '.sapMTokenText');
                            foreach ($tokens as $t) {
                                echo "\n - Token text: '" . trim($t->getText()) . "'";
                            }
                        } else {
                            $texts = $cell->findAll('css', '.sapMText, .sapMLnk, .sapMLabel');
                            foreach ($texts as $t) {
                                echo "\n - Text: '" . trim($t->getText()) . "'";
                            }
                        }
                        $sampleCount++;
                    }
                }
            }

            Assert::assertTrue($found, "Text '$text' not found in column '$columnName'");

        } catch (\Exception $e) {
            throw new \RuntimeException(
                sprintf(
                    "Failed to find text '%s' in column '%s'. Error: %s\nLast cell HTML: %s",
                    $text,
                    $columnName,
                    $e->getMessage(),
                    isset($cell) ? $cell->getOuterHtml() : 'No cell context'
                )
            );
        }
    }


    private function getCurrentUrlInfo(): array
    {
        // TODO move this to the UI5Browser
        return $this->getSession()->evaluateScript('
        (function() {
            var baseUrl = window.location.href.split("#")[0];
            var fullUrl = window.location.href;
            
            // UI5 specific routing information
            var ui5Hash = "";
            if (typeof sap !== "undefined" && 
                sap.ui && 
                sap.ui.core && 
                sap.ui.core.routing && 
                sap.ui.core.routing.HashChanger) {
                
                try {
                    // Get the current hash from UI5 router
                    ui5Hash = sap.ui.core.routing.HashChanger.getInstance().getHash();
                } catch(e) {
                    ui5Hash = window.location.hash.replace("#", "");
                }
            } else {
                ui5Hash = window.location.hash.replace("#", "");
            }
            
            return {
                baseUrl: baseUrl,
                fullUrl: fullUrl,
                hash: ui5Hash
            };
        })()
    ');
    }

    /**
     * Logs the current count of XHR requests for debugging purposes
     * 
     * @param string $context Optional context message to identify the log entry
     * @return void
     */
    private function logXHRCount(string $context = ''): void
    {
        try {
            // Check if XHR monitoring is initialized
            $isInitialized = $this->getSession()->evaluateScript('return typeof window.exfXHRLog !== "undefined"');

            if (!$isInitialized) {
                $this->getBrowser()->initializeXHRMonitoring();
            }

            // Get requests info
            $xhrRequests = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.requests) {
                    return window.exfXHRLog.requests.map(function(req) {
                        return {
                            url: req.url,
                            method: req.method,
                            status: req.status,
                            response: req.response
                        };
                    });
                }
                return [];
            ');

            // Log basic request count
            $message = "\n[XHR LOG] " . ($context ? "{$context} - " : '') . "Request Count: " . count($xhrRequests);

            // Get and log failed requests (status >= 400)
            $failedRequests = array_filter($xhrRequests, function ($req) {
                return isset($req['status']) && $req['status'] >= 400;
            });

            if (!empty($failedRequests)) {
                $message .= "\nFailed Requests:";
                foreach ($failedRequests as $req) {
                    $message .= sprintf(
                        "\n - %s %s (Status: %s)",
                        $req['method'] ?? 'Unknown',
                        $req['url'] ?? 'Unknown URL',
                        $req['status'] ?? 'Unknown Status'
                    );
                    if (isset($req['response'])) {
                        $message .= "\n   Response: " . substr($req['response'], 0, 200) . "...";
                    }
                }
            }

            // Get script errors
            $errors = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.errors) {
                    return window.exfXHRLog.errors.filter(function(err) {
                        return err.type === "JSError";
                    });
                }
                return [];
            ');

            if (!empty($errors)) {
                $message .= "\nJavaScript Errors:";
                foreach ($errors as $error) {
                    $message .= sprintf(
                        "\n - %s: %s",
                        $error['type'] ?? 'Unknown Error',
                        $error['message'] ?? 'No error message'
                    );
                }
            }

            echo $message . "\n";

        } catch (\Exception $e) {
            echo "\nWarning: Failed to log XHR details - " . $e->getMessage() . "\n";
        }
    }


    /**
     * @When I click button ":caption"
     */
    public function iClickButton(string $caption): void
    {

        $this->getBrowser()->waitForPageIsFullyLoaded(10);
        $this->getBrowser()->waitWhileAppBusy(30);
        $this->getBrowser()->waitForAjaxFinished(30);

        // Log XHR count when checking for errors
        $this->logXHRCount('iClickButton start xhr Count');

        // Get requests before click button
        $beforeRequests = $this->getSession()->evaluateScript('return window.exfXHRLog.requests.slice();');

        $btn = $this->getBrowser()->findButtonByCaption($caption);
        $this->logXHRCount('iClickButton Count');
        Assert::assertNotNull($btn, 'Cannot find button "' . $caption . '"');

        $btn->click();

        $this->getBrowser()->waitWhileAppBusy(30);
        $this->getBrowser()->waitForAjaxFinished(30);

        // After request list of after clicking the button
        $afterRequests = $this->getSession()->evaluateScript('return window.exfXHRLog.requests;');

        // Logging
        // echo "\nRequests triggered by button click:\n";
        // foreach ($afterRequests as $request) {
        //     if (!in_array($request, $beforeRequests)) {
        //         echo "URL: {$request['url']}, Status: {$request['status']}\n";
        //     }
        // }

        // // Log XHR count when checking for errors
        // $this->logXHRCount('iClickButton end xhr Count');
    }

    /**
     * 
     * @When I click tab ":caption"
     * 
     * @param string $caption
     * @return void
     */
    public function iClickTab(string $caption)
    {
        $this->getBrowser()->goToTab($caption);
    }

    /**
     * 
     * @When I type ":value" into ":caption"
     *
     * @param string $value
     * @param string $caption
     * @return void
     */
    public function iTypeIntoWidgetWithCaption(string $value, string $caption): void
    {
        $widget = $this->getBrowser()->findInputByCaption($caption);
        Assert::assertNotNull($widget, 'Cannot find input widget "' . $caption . '"');
        $widget->setValue($value);
    }

    /**
     * Focus a widget of a given type
     * 
     * @When I look at the first ":widgetType"
     * @When I look at ":widgetType" no. :number
     * 
     * @param string $widgetType
     * @return void
     */
    public function iLookAtWidget(string $widgetType, int $number = 1): void
    {
        $widgetNodes = $this->getBrowser()->findWidgets($widgetType);
        $node = $widgetNodes[$number - 1];
        Assert::assertNotNull($node, 'Cannot find "' . $widgetType . '" no. ' . $number . '!');
        $this->focus($node);
    }

    /**
     * @Then it has a column ":caption"
     * 
     * @param string $caption
     * @return void
     */
    public function itHasColumn(string $caption): void
    {
        /**
         * @var \Behat\Mink\Element\NodeElement $tableNode
         */
        $tableNode = $this->getFocusedNode();
        Assert::assertNotNull($tableNode, 'No widget has focus right now - cannot use steps like "it has..."');
        $colNode = $tableNode->find('css', 'td');
        Assert::assertNotNull($colNode, 'Column "' . $caption, '" not found');
    }

    protected function focus(NodeElement $node): void
    {
        $top = end($this->focusStack);
        if ($top !== $node) {
            $this->focusStack[] = $node;
        }
    }

    protected function getFocusedNode(): ?NodeElement
    {
        if (empty($this->focusStack)) {
            return null;
        }
        $top = end($this->focusStack);
        return $top;
    }


    /**
     * @Then the DataTable contains :text
     */
    public function theDataTableContains(string $text): void
    {
        try {
            // Find all DataTable widgets on the page
            $dataTables = $this->getBrowser()->findWidgets('DataTable');
            Assert::assertNotEmpty($dataTables, 'No DataTable found on page');
            // Get the first DataTable found
            $dataTable = $dataTables[0];

            // Search for text in all table cells
            $found = false;
            $cells = $dataTable->findAll('css', 'td');
            // Check each cell for the specified text
            foreach ($cells as $cell) {
                if (strpos($cell->getText(), $text) !== false) {
                    $found = true;
                    break;
                }
            }
            // Assert that text was found, throw exception if not
            Assert::assertTrue($found, "Text '$text' not found in DataTable");

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @Then I see at least one data item
     */
    public function iSeeFilteredResultsInDataTable(): void
    {
        try {
            // Wait for any pending operations to complete
            $this->getBrowser()->waitForAjaxFinished(10);
            $this->getBrowser()->waitWhileAppBusy(10);

            // Find DataTable widgets
            $dataTables = $this->getBrowser()->findWidgets('DataTable');
            Assert::assertNotEmpty($dataTables, 'No DataTable found on page');

            // Get the first DataTable
            $dataTable = $dataTables[0];

            // Look for different types of UI5 table classes
            $ui5TableSelectors = [
                '.sapMTable',        // Standard table
                '.sapUiTable',       // Grid table
                '.sapMList'          // List that might be used as table
            ];

            $ui5Table = null;
            foreach ($ui5TableSelectors as $selector) {
                $ui5Table = $dataTable->find('css', $selector);
                if ($ui5Table !== null) {
                    break;
                }
            }

            Assert::assertNotNull(
                $ui5Table,
                'No UI5 Table element found. Available classes: ' .
                implode(', ', array_map(function ($class) use ($dataTable) {
                    return $dataTable->find('css', $class) ? "$class (found)" : "$class (not found)";
                }, $ui5TableSelectors))
            );

            // Check for both standard rows and tree table rows
            $rows = $ui5Table->findAll('css', 'tr.sapMListItem, tr.sapUiTableRow');

            // Also check for no data indicator
            $noDataText = $ui5Table->find('css', '.sapMListNoData, .sapUiTableCtrlEmpty');
            if ($noDataText) {
                // If we have a no-data indicator, that's also a valid state
                return;
            }

            Assert::assertNotEmpty($rows, 'No rows found in filtered results');

            // Check for filter indicators
            $filterIndicators = [
                '.sapMTableFilterIcon',     // Standard table filter
                '.sapUiTableColFiltered'    // Grid table filter
            ];

            $hasFilter = false;
            foreach ($filterIndicators as $selector) {
                if ($dataTable->find('css', $selector)) {
                    $hasFilter = true;
                    break;
                }
            }

            // Log for debugging
            echo sprintf(
                "Found table with %d rows. Filter indicators: %s\n",
                count($rows),
                $hasFilter ? 'present' : 'not present'
            );

        } catch (\Exception $e) {
            // Get page content for debugging
            $pageContent = $this->getSession()->getPage()->getContent();
            echo "Page content sample: " . substr($pageContent, 0, 500) . "\n";

            throw new \Exception(
                "Failed to verify filtered results: " . $e->getMessage() .
                "\nDebug info: Current URL = " . $this->getSession()->getCurrentUrl()
            );
        }
    }



    /**
     * @BeforeScenario
     */
    public function resetAjaxLog(BeforeScenarioScope $scope)
    {
        if ($this->browser) {
            $this->browser->clearXHRLog();
            echo "\nXHR logs cleared before scenario: " . $scope->getScenario()->getTitle() . "\n";
        }
    }

    /**
     * @When I click the search-button
     */
    public function iClickButtonAndWaitForAjax(string $caption): void
    {
        try {
            // First, check if XHR monitoring is active
            $isXHRLogInitialized = $this->getSession()->evaluateScript('return typeof window.exfXHRLog !== "undefined"');

            if (!$isXHRLogInitialized) {
                echo "\nWarning: XHR monitoring not initialized. Reinitializing...\n";
                $this->getBrowser()->initializeXHRMonitoring();
            }

            // Now we can safely get the logs
            echo "\n------------ BEFORE CLICK ------------\n";
            $beforeLog = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.requests) {
                    return "Total Requests: " + window.exfXHRLog.requests.length + "\n"
                }
                return "No requests logged yet";
            ');
            echo $beforeLog;

            // Find and validate the button
            $btn = $this->getBrowser()->findButtonByCaption($caption);
            Assert::assertNotNull($btn, 'Cannot find button "' . $caption . '"');

            // Clear XHR logs
            $this->getBrowser()->clearXHRLog();

            // Debug information
            echo "\n------------ CLICKING BUTTON ------------\n";
            echo "Clicking button: " . $caption . "\n";

            // Click the button
            $btn->click();

            // Wait for AJAX operations to complete
            $this->waitForAjaxComplete();
            $this->getBrowser()->waitWhileAppBusy(5);

            // Show final state
            echo "\n------------ AFTER CLICK ------------\n";
            $afterLog = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.requests) {
                    return "Total Requests: " + window.exfXHRLog.requests.length + "\n";
                }
                return "No requests logged";
            ');
            echo $afterLog;

        } catch (\Exception $e) {
            echo "\nDebug - Button Click Error: " . $e->getMessage() . "\n";
            throw $e;
        }
    }



 

    /**
     * Waits for AJAX requests to complete
     * 
     * @param int $timeout Maximum wait time in seconds
     */
    private function waitForAjaxComplete(int $timeout = 30): void
    {
        $start = time();
        $lastError = null;

        while (time() - $start < $timeout) {
            // Check for AJAX errors
            $error = $this->getBrowser()->getAjaxError();

            // If no error, request is successful
            if ($error === null) {
                return;
            }

            // Special check for UI5 busy state
            if ($error['type'] === 'UI5Busy') {
                sleep(1);
                continue;
            }

            // Handle other error cases
            $lastError = $error;
            sleep(1);
        }

        // Handle timeout case
        $errorMsg = "AJAX requests did not complete within {$timeout} seconds.";
        if ($lastError) {
            $errorMsg .= "\nLast error: " . json_encode($lastError, JSON_PRETTY_PRINT);
        }
        throw new \Exception($errorMsg);
    }

  

    private function throwFormattedError(string $errorTitle, array $errors, array $fieldMappings): void
    {
        $errorDetails = $errorTitle . ":\n";
        foreach ($errors as $error) {
            foreach ($fieldMappings as $field => $label) {
                $value = $error[$field] ?? 'unknown';
                if ($field === 'response') {
                    $value = substr($value, 0, 500);
                }
                $errorDetails .= "{$label}: {$value}\n";
            }
            $errorDetails .= "\n";
        }

        throw new \Exception($errorDetails);
    }

    /**
     * @Then the page should be responsive
     */
    public function thePageShouldBeResponsive()
    {
        try {
            // Check if page is interactive
            $isResponsive = $this->getSession()->evaluateScript(
                'return document.readyState === "complete" && !document.querySelector(".sapUiBlockLayerTabbable")'
            );
            Assert::assertTrue($isResponsive, 'Page is not responsive');

            // Check for UI5 busy indicators
            $isBusy = $this->getSession()->evaluateScript(
                'return sap.ui.core.BusyIndicator._globalBusyIndicatorCounter > 0'
            );
            Assert::assertFalse($isBusy, 'Page is still showing busy indicator');

            // Her adımdan sonra hata kontrolü yap
            $this->assertNoErrors();

        } catch (\Exception $e) {
            throw new \RuntimeException('Page responsiveness check failed: ' . $e->getMessage());
        }
    }



    /**
     * @Then I wait for UI5 components to load
     */
    public function iWaitForUI5ComponentsToLoad()
    {
        try {
            $this->getBrowser()->waitForPageIsFullyLoaded(10);
            $this->getBrowser()->waitWhileAppBusy(30);
            $this->getBrowser()->waitForAjaxFinished(30);

            // Her adımdan sonra hata kontrolü yap
            $this->assertNoErrors();

            // Verify UI5 is properly initialized
            $ui5Ready = $this->getBrowser()->isUI5Ready();
            Assert::assertTrue($ui5Ready, 'UI5 framework failed to initialize properly');

        } catch (\Exception $e) {
            throw new \RuntimeException('Failed waiting for UI5 components: ' . $e->getMessage());
        }
    }

    /**
     * Checks for HTTP errors and UI5 error states
     * Throws exception if any errors detected
     * 
     * @throws \RuntimeException
     */
    private function assertNoErrors(): void
    {
        // Collect all types of errors
        $errors = [];

        // Check network errors (HTTP 4xx, 5xx)
        $networkErrors = $this->getSession()->evaluateScript('
        if (window.exfXHRLog && window.exfXHRLog.requests) {
            return window.exfXHRLog.requests.filter(function(req) {
                return req.status >= 400;
            }).map(function(req) {
                return {
                    type: "Network",
                    url: req.url,
                    status: req.status,
                    response: req.response,
                    timestamp: req.timestamp
                };
            });
        }
        return [];
    ');
        $errors = array_merge($errors, $networkErrors);

        // Check UI5 MessageManager errors
        $ui5Errors = $this->getSession()->evaluateScript('
        if (typeof sap !== "undefined" && sap.ui && sap.ui.getCore()) {
            var messageManager = sap.ui.getCore().getMessageManager();
            if (messageManager && messageManager.getMessageModel) {
                return messageManager.getMessageModel().getData()
                    .filter(function(msg) {
                        return msg.type === "Error" || msg.type === "Fatal";
                    })
                    .map(function(msg) {
                        return {
                            type: "UI5",
                            message: msg.message,
                            details: msg.description || ""
                        };
                    });
            }
        }
        return [];
    ');
        $errors = array_merge($errors, $ui5Errors);

        // Check JavaScript errors
        $jsErrors = $this->getSession()->evaluateScript('
        if (window.exfXHRLog && window.exfXHRLog.errors) {
            return window.exfXHRLog.errors.filter(function(err) {
                return err.type === "JSError";
            });
        }
        return [];
    ');
        $errors = array_merge($errors, $jsErrors);

        // If any errors found, throw exception with details
        if (!empty($errors)) {
            $errorMessage = "Errors detected during page operation:\n";
            foreach ($errors as $error) {
                $errorMessage .= sprintf(
                    "\nType: %s\n",
                    $error['type'] ?? 'Unknown'
                );

                if (isset($error['status'])) {
                    $errorMessage .= sprintf("Status: %s\n", $error['status']);
                }

                if (isset($error['url'])) {
                    $errorMessage .= sprintf("URL: %s\n", $error['url']);
                }

                if (isset($error['message'])) {
                    $errorMessage .= sprintf("Message: %s\n", $error['message']);
                }

                if (isset($error['response'])) {
                    $errorMessage .= sprintf(
                        "Response: %s\n",
                        substr($error['response'], 0, 500)
                    );
                }

                $errorMessage .= "------------------------\n";
            }

            throw new \RuntimeException($errorMessage);
        }
    }

    public function getWorkbench(): WorkbenchInterface
    {
        return $this->workbench;
    }

    public function __destruct()
    {
        UI5Browser::resetUser($this->workbench);
        $this->workbench->stop();
    }

    protected function getBrowser(): UI5Browser
    {
        if ($this->browser === null) {
            throw new \RuntimeException('BDT Browser not initialized!');
        }
        return $this->browser;
    }

    protected function splitArgument(string $delimitedList = null, string $delimiter = ','): array
    {
        if ($delimitedList === null) {
            return [];
        }
        $array = explode($delimiter, $delimitedList);
        $array = array_map('trim', $array);
        return $array;
    }
}
// v1 nice