<?php

/*
+---------------------------------------------------------------------------+
| OpenX v${RELEASE_MAJOR_MINOR}                                                                |
| =======${RELEASE_MAJOR_MINOR_DOUBLE_UNDERLINE}                                                                |
|                                                                           |
| Copyright (c) 2003-2009 OpenX Limited                                     |
| For contact details, see: http://www.openx.org/                           |
|                                                                           |
| This program is free software; you can redistribute it and/or modify      |
| it under the terms of the GNU General Public License as published by      |
| the Free Software Foundation; either version 2 of the License, or         |
| (at your option) any later version.                                       |
|                                                                           |
| This program is distributed in the hope that it will be useful,           |
| but WITHOUT ANY WARRANTY; without even the implied warranty of            |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             |
| GNU General Public License for more details.                              |
|                                                                           |
| You should have received a copy of the GNU General Public License         |
| along with this program; if not, write to the Free Software               |
| Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA |
+---------------------------------------------------------------------------+
$Id$
*/

require_once 'market-common.php';
require_once MAX_PATH .'/lib/OA/Admin/UI/component/Form.php';
require_once MAX_PATH .'/lib/OX/Admin/Redirect.php';
require_once MAX_PATH .'/lib/OA/Admin/UI/component/rule/DecimalPlaces.php';
require_once OX_MARKET_LIB_PATH . '/OX/oxMarket/Dal/CampaignsOptIn.php';;


// Security check
OA_Permission::enforceAccount(OA_ACCOUNT_MANAGER);

$oMarketComponent = OX_Component::factory('admin', 'oxMarket');
if (!$oMarketComponent->isMarketSettingsAlreadyShown()) {
    $oMarketComponent->setMarketSettingsAlreadyShown();
}
$defaultMinCpm = $oMarketComponent->getConfigValue('defaultFloorPrice');

/*-------------------------------------------------------*/
/* Display page                                          */
/*-------------------------------------------------------*/

//header
$oUI = OA_Admin_UI::getInstance();
$oUI->registerStylesheetFile(MAX::constructURL(MAX_URL_ADMIN, 'plugins/oxMarket/css/ox.market.css'));
$oMenu = OA_Admin_Menu::singleton();

// Register some variables from the request
$request = phpAds_registerGlobalUnslashed('campaignType', 'optInType', 'toOptIn', 'minCpm');
if (empty($campaignType)) {
    $campaignType = 'remnant';
}
if (empty($optInType)) {
    $optInType = 'remnant';
}
if (!isset($minCpm)) {
    $minCpm = formatCpm($defaultMinCpm);
}

// Prepare DAL instance
$oCampaignsOptInDal = new OX_oxMarket_Dal_CampaignsOptIn();

$oTpl = new OA_Plugin_Template('market-campaigns-settings.html','openXMarket');

$minCpms = array();
foreach ($_REQUEST as $param => $value) {
    if (preg_match("/^cpm\d+$/", $param)) {
        $minCpms[substr($param, 3)] = $value;
    }
}

// Perform opt-in if needed
if ('POST' == $_SERVER['REQUEST_METHOD'] && isDataValid($oTpl))
{
    performOptIn($minCpms, $oCampaignsOptInDal);
    exit(0);
}

$campaigns = $oCampaignsOptInDal->getCampaigns($defaultMinCpm, $campaignType, $minCpms);

// The number of campaigns of $campaignType that have already been
// opted in to the Market. We need this number to tell the difference between the
// $campaigns list being empty because all campaigns have been opted-in and because
// there are no campaigns in the inventory at all. In the first case $campaignsOptedIn
// will be > 0 and in the latter $campaignsOptedIn will be 0.
$campaignsOptedIn = $oCampaignsOptInDal->numberOfOptedCampaigns($campaignType);

// The number of remnant campaigns that can be opted-in.
// This will display in the "Opt in all X of your existing remnant campaigns to OpenX Market"
// radio button label on the screen.
$remnantCampaignsToOptIn = $oCampaignsOptInDal->numberOfRemnantCampaignsToOptIn();
$remnantCampaignsOptedIn = $oCampaignsOptInDal->numberOfOptedCampaigns('remnant');

$toOptIn = empty($toOptIn) ? array() : $toOptIn;


$aContentKeys = $oMarketComponent->retrieveCustomContent('market-quickstart');
if (!$aContentKeys) {
    $aContentKeys = array();
}
$topMessage = isset($aContentKeys['top-message'])
    ? $aContentKeys['top-message']
    : '';
    

$oTpl->assign('topMessage', $topMessage);
$oTpl->assign('campaigns', $campaigns);
$oTpl->assign('campaignsOptedIn', $campaignsOptedIn);
$oTpl->assign('campaignType', $campaignType);
$oTpl->assign('optInType', $optInType);
$oTpl->assign('remnantCampaignsCount', $remnantCampaignsToOptIn);
$oTpl->assign('remnantCampaignsOptedIn', $remnantCampaignsOptedIn);
$oTpl->assign('minCpm', $minCpm);
$oTpl->assign('minCpms', $minCpms);
$firstView = empty($toOptIn);
$oTpl->assign('firstView', $firstView);
$toOptInMap = arrayValuesToKeys($toOptIn);
foreach ($campaigns as $campaignId => $campaign) {
	if (!isset($toOptInMap[$campaignId])) {
	    $toOptInMap[$campaignId] = $firstView;
	}
}
$oTpl->assign('toOptIn', $toOptInMap);

// Display the page
$oCurrentSection = $oMenu->get("market-campaigns-settings");
phpAds_PageHeader("market-campaigns-settings", new OA_Admin_UI_Model_PageHeaderModel($oCurrentSection->getName(), "iconMarketLarge"), '../../', true, true, true, false);

$oTpl->display();

//footer
phpAds_PageFooter();

function isDataValid($template)
{
    global $optInType, $toOptIn, $minCpm;
    $valid = true;
    $zero = false;
    $decimalValidator = new OA_Admin_UI_Rule_DecimalPlaces();

    if ($optInType == 'remnant') {
        $valid = $decimalValidator->validate($minCpm, 2);
        if ($valid) {
            $zero = ($minCpm <= 0);
            $valid &= !$zero;
        }
        if (!$valid) {
            $template->assign('minCpmInvalid', true);
        }
    } else {
        $invalidCpms = array();
        foreach ($toOptIn as $campaignId) {
            $value = $_REQUEST['cpm' . $campaignId];
            $valueValid = $decimalValidator->validate($value, 2);
            if ($valueValid) {
                $valueZero = ($value <= 0);
                $valueValid &= !$valueZero;
                $zero |= $valueZero;
            }
            $valid &= $valueValid;

            if (!$valueValid) {
                $invalidCpms[$campaignId] = true;
            }
        }
        $template->assign('minCpmsInvalid', $invalidCpms);
    }

    if (!$valid) {
        if ($zero) {
            OA_Admin_UI::queueMessage('Please provide CPM values greater than zero', 'local', 'error', 0);
        } else {
            OA_Admin_UI::queueMessage('Please provide CPM values as decimal numbers with two digit precision', 'local', 'error', 0);
        }
    }
    return $valid;
}

function formatCpm($cpm)
{
    return number_format($cpm, 2, '.', '');
}

function performOptIn($minCpms, $oCampaignsOptInDal)
{
    global $optInType, $toOptIn, $minCpm, $campaignType;

    $campaignsOptedIn = $oCampaignsOptInDal->performOptIn($optInType, $minCpms, $toOptIn, $minCpm);
    
    OA_Admin_UI::queueMessage('You have successfully opted <b>' . $campaignsOptedIn . ' campaign' .
        ($campaignsOptedIn > 1 ? 's' : '') . '</b> into OpenX Market', 'local', 'confirm', 0);

    // Redirect back to the opt-in page
    $params = array('optInType' => $optInType, 'campaignType' => $campaignType, 'minCpm' => $minCpm);
    OX_Admin_Redirect::redirect('plugins/oxMarket/market-campaigns-settings.php?' . http_build_query($params));
}


/**
 * Same as array_fill_keys(array_keys($array), $valueToFillIn)
 * but compatible with PHP < 5.2
 *
 * @param array $array
 * @param mixed $valueToFillIn
 * @return array
 */
function arrayValuesToKeys($array, $valueToFillIn = true)
{
    $result = array();
    foreach ($array as $value) {
    	$result[$value] = $valueToFillIn;
    }
    return $result;
}

