<?php

/**
 * @file plugins/generic/orcidProfile/OrcidProfilePlugin.inc.php
 *
 * Copyright (c) 2015 University of Pittsburgh
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfilePlugin
 * @ingroup plugins_generic_orcidProfile
 *
 * @brief ORCID Profile plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class OrcidProfilePlugin extends GenericPlugin {
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled()) {
			// Register callback for Smarty filters; add CSS
			HookRegistry::register('TemplateManager::display', array(&$this, 'handleTemplateDisplay'));

			// Insert ORCID callback
			HookRegistry::register('LoadHandler', array(&$this, 'setupCallbackHandler'));
		}
		return $success;
	}

	/**
	 * Get page handler path for this plugin.
	 * @return string Path to plugin's page handler
	 */
	function getHandlerPath() {
		return $this->getPluginPath() . DIRECTORY_SEPARATOR . 'pages';
	}

	/**
	 * Hook callback: register pages for each sushi-lite method
	 * This URL is of the form: orcidapi/{$orcidrequest}
	 * @see PKPPageRouter::route()
	 */
	function setupCallbackHandler($hookName, $params) {
		$page = $params[0];
		if ($this->getEnabled() && $page == 'orcidapi') {
			$this->import('pages/OrcidHandler');
			define('HANDLER_CLASS', 'OrcidHandler');
			return true;
		}
		return false;
	}

	/**
	 * Hook callback: register output filter to add data citation to submission
	 * summaries; add data citation to reading tools' suppfiles and metadata views.
	 * @see TemplateManager::display()
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr =& $args[0];
		$template =& $args[1];
                $request =& PKPApplication::getRequest();

                // Assign our private stylesheet.
                $templateMgr->addStylesheet($request->getBaseUrl() . '/' . $this->getStyleSheet());

		switch ($template) {
			case 'user/register.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'registrationFilter'));
				break;
			case 'user/profile.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'profileFilter'));
				break;
			case 'author/submit/step3.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'submitFilter'));
				break;
		}
		return false;
	}

	/**
	 * Output filter adds ORCiD interaction to registration form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function registrationFilter($output, &$templateMgr) {
		if (preg_match('/<form id="registerForm"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$journal = Request::getJournal();
			$templateMgr->assign('targetOp', 'register');

			if (!Request::getUserVar('hideOrcid')) {
				// Entering the registration without ORCiD; present the button.
				$templateMgr->assign(array(
					'orcidProfileAPIPath' => $this->getSetting($journal->getId(), 'orcidProfileAPIPath'),
					'orcidClientId' => $this->getSetting($journal->getId(), 'orcidClientId'),
				));

				$newOutput = substr($output, 0, $offset);
				$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidProfile.tpl');
				$newOutput .= substr($output, $offset);
				$output = $newOutput;
			} else {
				// If we're returning from an ORCiD auth process, alter the form.
				$newOutput = substr($output, 0, $offset) . $match;
				$newOutput .= '<input type="hidden" name="orcidAuth" value="' . htmlspecialchars(Request::getUserVar('orcidAuth')) . '" />';
				$newOutput .= '<script type="text/javascript">
				        $(document).ready(function() {
						$(\'#orcid\').attr(\'readonly\', "true");
					});
				</script>';
				$newOutput .= substr($output, $offset + strlen($match));
				$output = $newOutput;
			}
		}
		$templateMgr->unregister_outputfilter('registrationFilter');
		return $output;
	}

	/**
	 * Output filter adds ORCiD interaction to user profile form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function profileFilter($output, &$templateMgr) {
		if (preg_match('/<form id="profile"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$journal = Request::getJournal();
			$templateMgr->assign('targetOp', 'profile');

			// Entering the registration without ORCiD; present the button.
			$templateMgr->assign(array(
				'orcidProfileAPIPath' => $this->getSetting($journal->getId(), 'orcidProfileAPIPath'),
				'orcidClientId' => $this->getSetting($journal->getId(), 'orcidClientId'),
			));

			$newOutput = substr($output, 0, $offset);
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidProfile.tpl');
			$newOutput .= '<script type="text/javascript">
			        $(document).ready(function() {
					$(\'#orcid\').attr(\'readonly\', "true");
				});
			</script>';
			$newOutput .= substr($output, $offset);
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('profileFilter');
		return $output;
	}

	/**
	 * Output filter adds ORCiD interaction to the 3rd step submission form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function submitFilter($output, &$templateMgr) {
		if (preg_match('/<input type="text" class="textField" name="authors\[0\]\[orcid\][^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$journal = Request::getJournal();
			$templateMgr->assign('targetOp', 'submit');

			// Entering the registration without ORCiD; present the button.
			$templateMgr->assign(array(
				'orcidProfileAPIPath' => $this->getSetting($journal->getId(), 'orcidProfileAPIPath'),
				'orcidClientId' => $this->getSetting($journal->getId(), 'orcidClientId'),
			));

			$newOutput = substr($output, 0, $offset + strlen($match) - 1);
			$newOutput .= ' readonly=\'readonly\'><br />';
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidProfile.tpl');
			$newOutput .= '<button id="remove-orcid-button">Remove ORCID ID</button>
<script>$("#remove-orcid-button").click(function(event) {
	event.preventDefault(); 
	$("#authors-0-orcid").val("");
 });</script>';
			$newOutput .= substr($output, $offset + strlen($match));
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('submitFilter');
		return $output;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.orcidProfile.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.orcidProfile.description');
	}

	/**
	 * Extend the {url ...} smarty to support this plugin.
	 */
	function smartyPluginUrl($params, &$smarty) {
		$path = array($this->getCategory(), $this->getName());
		if (is_array($params['path'])) {
			$params['path'] = array_merge($path, $params['path']);
		} elseif (!empty($params['path'])) {
			$params['path'] = array_merge($path, array($params['path']));
		} else {
			$params['path'] = $path;
		}

		if (!empty($params['id'])) {
			$params['path'] = array_merge($params['path'], array($params['id']));
			unset($params['id']);
		}
		return $smarty->smartyUrl($params, $smarty);
	}

	/**
	 * Set the page's breadcrumbs, given the plugin's tree of items
	 * to append.
	 * @param $subclass boolean
	 */
	function setBreadcrumbs($isSubclass = false) {
		$templateMgr =& TemplateManager::getManager();
		$pageCrumbs = array(
			array(
				Request::url(null, 'user'),
				'navigation.user'
			),
			array(
				Request::url(null, 'manager'),
				'user.role.manager'
			)
		);
		if ($isSubclass) {
			$pageCrumbs[] = array(
				Request::url(null, 'manager', 'plugins'),
				'manager.plugins'
			);
			$pageCrumbs[] = array(
				Request::url(null, 'manager', 'plugins', 'generic'),
				'plugins.categories.generic'
			);
		}

		$templateMgr->assign('pageHierarchy', $pageCrumbs);
	}

	/**
	 * Display verbs for the management interface.
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array('settings', __('manager.plugins.settings'));
		}
		return parent::getManagementVerbs($verbs);
	}

	/**
	 * Execute a management verb on this plugin
	 * @param $verb string
	 * @param $args array
	 * @param $message string Result status message
	 * @param $messageParams array Parameters for the message key
	 * @return boolean
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		$journal =& Request::getJournal();
		if (!parent::manage($verb, $args, $message, $messageParams)) {
			if ($verb == 'enable' && !$this->getSetting($journal->getId(), 'orcidProfileAPIPath')) {
				// default the 1.2 public API if no setting is present
				$this->updateSetting($journal->getId(), 'orcidProfileAPIPath', 'http://pub.orcid.org/v1.2/', 'string');
			} else {
				return false;
			}
		}

		switch ($verb) {
			case 'settings':
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));

				$this->import('OrcidProfileSettingsForm');
				$form = new OrcidProfileSettingsForm($this, $journal->getId());
				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						Request::redirect(null, 'manager', 'plugin');
						return false;
					} else {
						$this->setBreadcrumbs(true);
						$form->display();
					}
				} else {
					$this->setBreadcrumbs(true);
					$form->initData();
					$form->display();
				}
				return true;
			default:
				// Unknown management verb
				assert(false);
				return false;
		}
	}

	/**
         * Return the location of the plugin's CSS file
         * @return string
         */
        function getStyleSheet() {
                return $this->getPluginPath() . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'orcidProfile.css';
        }
}
?>
