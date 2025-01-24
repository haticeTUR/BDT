<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Facades\AbstractAjaxFacade\AbstractAjaxFacade;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Factories\WidgetFactory;
use exface\UI5Facade\Facades\UI5Facade;
use PHPUnit\Framework\Assert;

/**
 * Allows to work with OpenUI5 apps generated by exface.UI5Facade
 * 
 * @author Andrej Kabachnik
 */
class UI5Browser
{
    private $session;

    private $workbench = null;

    private $facade = null;

    // Constructor
    public function __construct(Session $session, string $ui5AppUrl)
    {
        $this->session = $session;
        $this->waitForAppLoaded($ui5AppUrl);
        $this->workbench = new Workbench();
    }

    /**
     * Waits for OpenUI5 framework to load and initialize
     * 
     * @param int $timeoutInSeconds Maximum time to wait for UI5 loading (default: 30 seconds)
     * @return bool Returns true if UI5 loaded successfully, false otherwise
     * 
     * Step-by-step process:
     * 1. Checks if global 'sap' object exists
     * 2. Verifies sap.ui namespace is available
     * 3. Confirms sap.ui.getCore() method exists
     * 4. Validates core object is accessible
     * 5. Ensures core.getLoadedLibraries function is available
     */
    protected function waitForUI5Loading(int $timeoutInSeconds = 30): bool
    {
        return $this->getSession()->wait(
            $timeoutInSeconds * 1000,
            <<<JS
            (function() {
                // Check if base SAP namespace exists
                if (typeof sap === 'undefined') {
                    console.log('SAP not defined');
                    return false;
                }
                
                // Verify UI namespace is available
                if (typeof sap.ui === 'undefined') {
                    console.log('sap.ui not defined');
                    return false;
                }
                
                // Confirm core method exists
                if (typeof sap.ui.getCore === 'undefined') {
                    console.log('sap.ui.getCore not defined');
                    return false;
                }
                
                // Get core instance and validate
                var core = sap.ui.getCore();
                if (!core) {
                    console.log('core not available');
                    return false;
                }
                
                // Final check - ensure core libraries are loaded
                return typeof core.getLoadedLibraries === 'function';
            })()
    JS
        );
    }

    /**
     * Waits for UI5 controls to render in the page
     * 
     * @param int $timeoutInSeconds Maximum wait time (default: 30 seconds)
     * @return bool Returns true if UI5 controls are found, false if timeout reached
     * 
     * Verification process:
     * 1. Checks if UI5 framework is loaded (sap and sap.ui objects)
     * 2. Searches page content for UI5-specific markers:
     *    - 'sapUiView' - indicates presence of UI5 views
     *    - 'sapMPage' - indicates presence of UI5 mobile pages
     */
    protected function waitForUI5Controls(int $timeoutInSeconds = 30): bool
    {
        return $this->getSession()->wait(
            $timeoutInSeconds * 1000,
            <<<JS
            (function() {
                // Verify UI5 base requirements
                if (typeof sap === 'undefined' || typeof sap.ui === 'undefined') return false;
                
                // Search page content for UI5 view markers
                var content = document.body.innerHTML;
                
                // Check for either standard view or mobile page indicators
                return content.indexOf('sapUiView') !== -1 || content.indexOf('sapMPage') !== -1;
            })()
    JS
        );
    }



    /**
     * 
     * @param string $caption
     * @param \Behat\Mink\Element\NodeElement|null $parent
     * @return NodeElement|null
     */
    public function findInputByCaption(string $caption, NodeElement $parent = null): ?NodeElement
    {
        $page = $this->getPage();
        $input = null;
        // $input = $page->find('xpath', '//*/label/span/bdi[contains(text(), "' . $caption . '")]');
        // $labelBdi = $page->find('named', ['content', $caption]);
        $labelBdis = ($parent ?? $page)->findAll('css', 'label.sapMLabel > span > bdi');
        foreach ($labelBdis as $labelBdi) {
            if ($labelBdi->getText() === $caption) {
                $sapMLabel = $labelBdi->getParent()->getParent();
                $labelFor = $sapMLabel->getAttribute('for');
                $input = $sapMLabel->getParent()->getParent()->findById($labelFor);
                break;
            }
        }
        return $input;
    }

    /**
     * 
     * @param string $caption
     * @param \Behat\Mink\Element\NodeElement|null $parent
     * @return NodeElement
     */
    public function findButtonByCaption(string $caption, NodeElement $parent = null): ?NodeElement
    {
        $page = $this->getPage();
        // $input = $page->find('xpath', '//*/label/span/bdi[contains(text(), "' . $caption . '")]');
        // $labelBdi = $page->find('named', ['content', $caption]);
        $labelBdis = ($parent ?? $page)->findAll('css', 'button.sapMBtn > span > span > bdi');
        foreach ($labelBdis as $labelBdi) {
            if ($labelBdi->getText() === $caption) {
                $button = $labelBdi->getParent()->getParent()->getParent();
                break;
            }
        }
        return $button;
    }

    public function getPage()
    {
        return $this->session->getPage();
    }

    /**
     * Waits the complete UI5 application loading process
     * 
     * @param string $pageUrl URL of the UI5 application
     * @return void
     * 
     * Loading sequence:
     * 1. Waits for initial page load completion
     * 2. Ensures UI5 framework is loaded
     * 3. Waits for UI5 controls to render
     * 4. Validates app ID presence
     * 5. Checks bussy state resolution
     * 6. Confirm AJAX requests completion
     */
    protected function waitForAppLoaded(string $pageUrl)
    {
        // Wait for initial page DOM to be ready
        $this->waitForPageIsFullyLoaded(10);

        // Ensure UI5 framework is loaded and initialized
        if (!$this->waitForUI5Loading(30)) {
            error_log("Warning: UI5 failed to load or not ready");
        }

        // Wait for UI5 controls to be rendered
        if (!$this->waitForUI5Controls(30)) {
            error_log("Warning: UI5 controls failed to load");
        }

        // Extract and validate app ID from URL
        $appId = StringDataType::substringBefore($pageUrl, '.html', $pageUrl) . '.app';
        $this->waitForNodeId($appId, 30);

        // Check app's busy state and AJAX completion
        $this->waitWhileAppBusy(30);
        $this->waitForAjaxFinished(30);
    }


    /**
     * Waits for UI5 controls to render in the page
     * 
     * @param string $componentType
     * @param int $timeoutInSeconds Maximum wait time (default: 30 seconds)
     * @return bool Returns true if UI5 controls are found, false if timeout reached
     * 
     * Verification process:
     * 1. Checks if UI5 framework is loaded (sap and sap.ui objects)
     * 2. Searches page content for UI5-specific markers:
     *    - 'sapUiView' - indicates presence of UI5 views
     *    - 'sapMPage' - indicates presence of UI5 mobile pages
     */
    public function waitForUI5Component(string $componentType, int $timeoutInSeconds = 30): bool
    {
        return $this->getSession()->wait(
            $timeoutInSeconds * 1000,
            <<<JS
            (function() {
                // Check for UI5 framework availability
                if (typeof sap === 'undefined' || typeof sap.ui === 'undefined') return false;
                
                // Look for elements with component-specific class
                var elements = document.getElementsByClassName('sap{$componentType}');
                
                // Return true if at least one component found
                return elements.length > 0;
            })()
    JS
        );
    }

    /**
     * Checks the complete UI5 application loading process
     * 
     * @param string $pageUrl URL of the UI5 application
     * @return void
     * 
     * Loading sequence:
     * 1. Waits for initial page load completion
     * 2. Ensures UI5 framework is loaded
     * 3. Waits for UI5 controls to render
     * 4. Validates app ID presence
     * 5. Checks busy state resolution
     * 6. Confirms AJAX requests completion
     */
    public function isUI5Ready(): bool
    {
        return $this->getSession()->evaluateScript(
            <<<JS
            (function() {
                // Verify UI5 framework existence
                if (typeof sap === 'undefined' || typeof sap.ui === 'undefined') return false;
                
                // Get and validate core object
                var core = sap.ui.getCore();
                
                // Check core functionality
                return core && typeof core.getLoadedLibraries === 'function';
            })()
    JS
        );
    }


    /**
     * 
     * @param string $id
     * @param int $timeoutInSeconds
     * @return void
     */
    protected function waitForNodeId(string $id, int $timeoutInSeconds = 10)
    {
        $page = $this->getPage();
        $page->waitFor(
            $timeoutInSeconds * 1000,
            function () use ($page, $id) {
                $app = $page->findById($id);
                return $app && $app->isVisible();
            }
        );
    }

    /**
     * 
     * @param int $timeoutInSeconds
     * @return bool
     */
    public function waitWhileAppBusy(int $timeoutInSeconds = 10): bool
    {
        return $this->getSession()->wait(
            $timeoutInSeconds * 1000,
            <<<JS
            (function() {
                if (document.readyState !== "complete") {
                    return false;
                }
                if ((typeof $ !== 'undefined') && $.active !== 0) {
                    return false;
                }/*
                if ((typeof XMLHttpRequest !== 'undefined') && XMLHttpRequest.prototype.readyState !== 4) {
                    return false;
                }*/
                if ((typeof exfLauncher === 'undefined') || exfLauncher === undefined) {
                    return false;
                }
                return exfLauncher.isBusy() === false;
            })()
JS
        );
    }

    /**
     * 
     * @param int $timeoutInSeconds
     * @return bool
     */
    public function waitForAjaxFinished(int $timeoutInSeconds = 10): bool
    {
        return $this->getSession()->wait(
            $timeoutInSeconds * 1000,
            <<<JS
            (function() {
                if (typeof $ !== 'undefined') {
                    return $.active === 0;
                }
                if (typeof XMLHttpRequest !== 'undefined') {
                    return XMLHttpRequest.prototype.readyState === 4;
                }
                return true;
            })()
JS
        );
    }

    /**
     * 
     * @param mixed $timeoutInSeconds
     * @return void
     */
    public function waitForPageIsFullyLoaded($timeoutInSeconds = 5)
    {
        $this->getSession()->wait(
            $timeoutInSeconds * 1000,
            <<<JS
            document.readyState === "complete"
JS
        );
    }

    /**
     * 
     * @param string $widgetType
     * @param int $timeoutInSeconds
     * @return NodeElement[]
     */
    public function findWidgets(string $widgetType, NodeElement $parent = null, int $timeoutInSeconds = 2): array
    {

        // Ensure UI5 is ready before searching
        if (!$this->isUI5Ready()) {
            $this->waitForUI5Loading($timeoutInSeconds);
        }

        // Special handling for DataTable widgets
        if ($widgetType === 'DataTable') {
            $this->waitForUI5Component('Table', $timeoutInSeconds);
        }

        // Construct widget selector
        $cssSelector = ".exfw-{$widgetType}";

        // Wait for widgets to appear in DOM
        $timeout = $this->getSession()->wait(
            $timeoutInSeconds * 1000,
            <<<JS
        (function() {
            // Check for matching elements
            var jqEls = $('{$cssSelector}');
            return jqEls.length !== 0;
        })()
JS
        );

        // Get page reference and find all matching widgets
        $page = $this->getPage();
        $widgets = $page->findAll('css', $cssSelector);

        // Filter for visible widgets only
        $result = [];
        foreach ($widgets as $w) {
            if ($w->isVisible()) {
                $result[] = $w;
            }
        }
        return $result;
    }

    /**
     * Returns the type of the widget, that the given node belongs to
     * 
     * @param \Behat\Mink\Element\NodeElement $node
     * @return bool|string|null
     */
    public function getNodeWidgetType(NodeElement $node): ?string
    {
        $classes = $node->getAttribute('class');
        $type = null;
        foreach (explode(' ', $classes ?? '') as $class) {
            if (mb_stripos($class, 'exfw-') === 0) {
                $type = StringDataType::substringAfter($class, 'exfw-');
            }
        }
        if ($type === null) {
            // TODO search the parents of the node for the first one with `exfw` CSS class
            // and take the widget type from that node
        }
        return $type;
    }

    /**
     * 
     * @return \Behat\Mink\Session
     */
    protected function getSession(): Session
    {
        return $this->session;
    }

    /**
     * 
     * @return Workbench
     */
    public function getWorkbench()
    {
        return $this->workbench;
    }

    /**
     * 
     * @return \exface\UI5Facade\Facades\UI5Facade
     */
    public function getFacade() : UI5Facade
    {
        if ($this->facade === null) {
            $this->facade = FacadeFactory::createFromString(UI5Facade::class, $this->getWorkbench());
        }   
        return $this->facade;
    }

    /**
     * 
     * @param string $pageUrl
     * @param string $widgetId
     * @param string $assertWidgetType
     * @return \exface\Core\Interfaces\WidgetInterface
     */
    protected function getWidget(string $pageUrl, string $widgetId = null, string $assertWidgetType = null)
    {
        $page = UiPageFactory::createFromModel($this->getWorkbench(), $pageUrl);
        $widget = $widgetId !== null ? $page->getWidget($widgetId) : $page->getWidgetRoot();
        if ($assertWidgetType !== null) {
            Assert::assertEquals($assertWidgetType, $widget->getType(), 'Widget ' . $widgetId . ' is not of expected type "' . $assertWidgetType . '"!');
        }
        return $widget;
    }
}