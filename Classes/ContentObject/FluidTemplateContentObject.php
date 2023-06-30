<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Frontend\ContentObject;

use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Extbase\Mvc\Web\RequestBuilder;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Frontend\ContentObject\Exception\ContentRenderingException;

/**
 * Contains FLUIDTEMPLATE class object
 */
class FluidTemplateContentObject extends AbstractContentObject
{
    public function __construct(
        protected ContentDataProcessor $contentDataProcessor,
        protected StandaloneView $view
    ) {
    }

    /**
     * Rendering the cObject, FLUIDTEMPLATE
     *
     * Configuration properties:
     * - file string+stdWrap The FLUID template file
     * - layoutRootPaths array of filepath+stdWrap Root paths to layouts (fallback)
     * - partialRootPaths array of filepath+stdWrap Root paths to partials (fallback)
     * - variable array of cObjects, the keys are the variable names in fluid
     * - dataProcessing array of data processors which are classes to manipulate $data
     * - extbase.pluginName
     * - extbase.controllerExtensionName
     * - extbase.controllerName
     * - extbase.controllerActionName
     *
     * Example:
     * 10 = FLUIDTEMPLATE
     * 10.templateName = MyTemplate
     * 10.templateRootPaths.10 = EXT:site_configuration/Resources/Private/Templates/
     * 10.partialRootPaths.10 = EXT:site_configuration/Resources/Private/Partials/
     * 10.layoutRootPaths.10 = EXT:site_configuration/Resources/Private/Layouts/
     * 10.variables {
     *   mylabel = TEXT
     *   mylabel.value = Label from TypoScript coming
     * }
     *
     * @param array $conf Array of TypoScript properties
     * @return string The HTML output
     */
    public function render($conf = [])
    {
        $this->view->setRequest($this->request);

        if (!is_array($conf)) {
            $conf = [];
        }

        $this->setFormat($conf);
        $this->setTemplate($conf);
        $this->setLayoutRootPath($conf);
        $this->setPartialRootPath($conf);
        $this->setExtbaseVariables($conf);
        $this->assignSettings($conf);
        $variables = $this->getContentObjectVariables($conf);
        $variables = $this->contentDataProcessor->process($this->cObj, $conf, $variables);

        $this->view->assignMultiple($variables);

        $this->renderFluidTemplateAssetsIntoPageRenderer($variables);
        $content = $this->renderFluidView();
        return $this->applyStandardWrapToRenderedContent($content, $conf);
    }

    /**
     * Attempts to render HeaderAssets and FooterAssets sections from the
     * Fluid template, then adds each (if not empty) to either header or
     * footer, as appropriate, using PageRenderer.
     */
    protected function renderFluidTemplateAssetsIntoPageRenderer(array $variables)
    {
        $pageRenderer = $this->getPageRenderer();
        $headerAssets = $this->view->renderSection('HeaderAssets', [...$variables, 'contentObject' => $this], true);
        $footerAssets = $this->view->renderSection('FooterAssets', [...$variables, 'contentObject' => $this], true);
        if (!empty(trim($headerAssets))) {
            $pageRenderer->addHeaderData($headerAssets);
        }
        if (!empty(trim($footerAssets))) {
            $pageRenderer->addFooterData($footerAssets);
        }
    }

    /**
     * Set template
     *
     * @param array $conf With possibly set file resource
     * @throws \InvalidArgumentException
     */
    protected function setTemplate(array $conf)
    {
        // Fetch the Fluid template by templateName
        if (
            (!empty($conf['templateName']) || !empty($conf['templateName.']))
            && !empty($conf['templateRootPaths.']) && is_array($conf['templateRootPaths.'])
        ) {
            $templateRootPaths = $this->applyStandardWrapToFluidPaths($conf['templateRootPaths.']);
            $this->view->setTemplateRootPaths($templateRootPaths);
            $templateName = $this->cObj->stdWrapValue('templateName', $conf ?? []);
            $this->view->setTemplate($templateName);
        } elseif (!empty($conf['template']) && !empty($conf['template.'])) {
            // Fetch the Fluid template by template cObject
            $templateSource = $this->cObj->cObjGetSingle($conf['template'], $conf['template.'], 'template');
            if ($templateSource === '') {
                throw new ContentRenderingException(
                    'Could not find template source for ' . $conf['template'],
                    1437420865
                );
            }
            $this->view->setTemplateSource($templateSource);
        } else {
            // Fetch the Fluid template by file stdWrap
            $file = (string)$this->cObj->stdWrapValue('file', $conf ?? []);
            // Get the absolute file name
            $templatePathAndFilename = GeneralUtility::getFileAbsFileName($file);
            $this->view->setTemplatePathAndFilename($templatePathAndFilename);
        }
    }

    /**
     * Set layout root path if given in configuration
     *
     * @param array $conf Configuration array
     */
    protected function setLayoutRootPath(array $conf)
    {
        // Override the default layout path via typoscript
        $layoutPaths = [];

        $layoutRootPath = (string)$this->cObj->stdWrapValue('layoutRootPath', $conf ?? []);
        if ($layoutRootPath !== '') {
            $layoutPaths[] = GeneralUtility::getFileAbsFileName($layoutRootPath);
        }
        if (isset($conf['layoutRootPaths.'])) {
            $layoutPaths = array_replace($layoutPaths, $this->applyStandardWrapToFluidPaths($conf['layoutRootPaths.']));
        }
        if (!empty($layoutPaths)) {
            $this->view->setLayoutRootPaths($layoutPaths);
        }
    }

    /**
     * Set partial root path if given in configuration
     *
     * @param array $conf Configuration array
     */
    protected function setPartialRootPath(array $conf)
    {
        $partialPaths = [];

        $partialRootPath = (string)$this->cObj->stdWrapValue('partialRootPath', $conf ?? []);
        if ($partialRootPath !== '') {
            $partialPaths[] = GeneralUtility::getFileAbsFileName($partialRootPath);
        }
        if (isset($conf['partialRootPaths.'])) {
            $partialPaths = array_replace($partialPaths, $this->applyStandardWrapToFluidPaths($conf['partialRootPaths.']));
        }
        if (!empty($partialPaths)) {
            $this->view->setPartialRootPaths($partialPaths);
        }
    }

    /**
     * Set different format if given in configuration
     *
     * @param array $conf Configuration array
     */
    protected function setFormat(array $conf)
    {
        $format = $this->cObj->stdWrapValue('format', $conf ?? []);
        if ($format) {
            $this->view->setFormat($format);
        }
    }

    /**
     * Set some extbase variables if given
     *
     * @param array $conf Configuration array
     */
    protected function setExtbaseVariables(array $conf)
    {
        // @todo: It is currently unclear if the if's below can happen at all: An extbase request has been
        //        prepared, but the setup of plugin name, controller extension name and friends
        //        did not happen? Maybe these four if's are useless and the main if that
        //        tests for all four properties is fine? Maybe the main if below is obsolete, too?
        //        This comment was added when StandaloneView still had a default constructor that actively
        //        creates a request by default. It might be more possible to resolve this when this is gone.
        $request = $this->request;
        $requestPluginName = (string)$this->cObj->stdWrapValue('pluginName', $conf['extbase.'] ?? []);
        if ($requestPluginName && $request instanceof RequestInterface) {
            $request = $request->withPluginName($requestPluginName);
            $this->view->setRequest($request);
        }
        $requestControllerExtensionName = (string)$this->cObj->stdWrapValue('controllerExtensionName', $conf['extbase.'] ?? []);
        if ($requestControllerExtensionName && $request instanceof RequestInterface) {
            $request = $request->withControllerExtensionName($requestControllerExtensionName);
            $this->view->setRequest($request);
        }
        $requestControllerName = (string)$this->cObj->stdWrapValue('controllerName', $conf['extbase.'] ?? []);
        if ($requestControllerName && $request instanceof RequestInterface) {
            $request = $request->withControllerName($requestControllerName);
            $this->view->setRequest($request);
        }
        $requestControllerActionName = (string)$this->cObj->stdWrapValue('controllerActionName', $conf['extbase.'] ?? []);
        if ($requestControllerActionName && $request instanceof RequestInterface) {
            $request = $request->withControllerActionName($requestControllerActionName);
            $this->view->setRequest($request);
        }

        if ($requestPluginName && $requestControllerExtensionName && $requestControllerName && $requestControllerActionName) {
            // @todo: Yep, ugly. Having all four properties indicates an extbase plugin and then starts
            //        extbase configuration manager. See https://forge.typo3.org/issues/78842 and investigate
            //        if we still need this?
            $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
            $configurationManager->setConfiguration([
                'extensionName' => $requestControllerExtensionName,
                'pluginName' => $requestPluginName,
            ]);
            if (!isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$requestControllerExtensionName]['plugins'][$requestPluginName]['controllers'])) {
                $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$requestControllerExtensionName]['plugins'][$requestPluginName]['controllers'] = [
                    $requestControllerName => [
                        'actions' => [
                            $requestControllerActionName,
                        ],
                    ],
                ];
            }
            $requestBuilder = GeneralUtility::makeInstance(RequestBuilder::class);
            $this->request = $requestBuilder->build($this->request);
            $this->view->setRequest($this->request);
        }
    }

    /**
     * Compile rendered content objects in variables array ready to assign to the view
     *
     * @param array $conf Configuration array
     * @return array the variables to be assigned
     * @throws \InvalidArgumentException
     */
    protected function getContentObjectVariables(array $conf)
    {
        $variables = [];
        $reservedVariables = ['data', 'current'];
        // Accumulate the variables to be process and loop them through cObjGetSingle
        $variablesToProcess = (array)($conf['variables.'] ?? []);
        foreach ($variablesToProcess as $variableName => $cObjType) {
            if (is_array($cObjType)) {
                continue;
            }
            if (!in_array($variableName, $reservedVariables)) {
                $cObjConf = $variablesToProcess[$variableName . '.'] ?? [];
                $variables[$variableName] = $this->cObj->cObjGetSingle($cObjType, $cObjConf, 'variables.' . $variableName);
            } else {
                throw new \InvalidArgumentException(
                    'Cannot use reserved name "' . $variableName . '" as variable name in FLUIDTEMPLATE.',
                    1288095720
                );
            }
        }
        $variables['data'] = $this->cObj->data;
        $variables['current'] = $this->cObj->data[$this->cObj->currentValKey ?? null] ?? null;
        return $variables;
    }

    /**
     * Set any TypoScript settings to the view. This is similar to a
     * default MVC action controller in extbase.
     *
     * @param array $conf Configuration
     */
    protected function assignSettings(array $conf)
    {
        if (isset($conf['settings.'])) {
            $typoScriptService = GeneralUtility::makeInstance(TypoScriptService::class);
            $settings = $typoScriptService->convertTypoScriptArrayToPlainArray($conf['settings.']);
            $this->view->assign('settings', $settings);
        }
    }

    /**
     * Render fluid standalone view
     *
     * @return string
     */
    protected function renderFluidView()
    {
        return $this->view->render();
    }

    /**
     * Apply standard wrap to content
     *
     * @param string $content Rendered HTML content
     * @param array $conf Configuration array
     * @return string Standard wrapped content
     */
    protected function applyStandardWrapToRenderedContent($content, array $conf)
    {
        if (isset($conf['stdWrap.'])) {
            $content = $this->cObj->stdWrap($content, $conf['stdWrap.']);
        }
        return $content;
    }

    /**
     * Applies stdWrap on Fluid path definitions
     *
     *
     * @return array
     */
    protected function applyStandardWrapToFluidPaths(array $paths)
    {
        $finalPaths = [];
        foreach ($paths as $key => $path) {
            if (str_ends_with((string)$key, '.')) {
                if (isset($paths[substr($key, 0, -1)])) {
                    continue;
                }
                $path = $this->cObj->stdWrap('', $path);
            } elseif (isset($paths[$key . '.'])) {
                $path = $this->cObj->stdWrap($path, $paths[$key . '.']);
            }
            $finalPaths[$key] = GeneralUtility::getFileAbsFileName($path);
        }
        return $finalPaths;
    }
}
