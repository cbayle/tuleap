<?php
/**
 * Copyright (c) Enalean, 2011, 2012, 2013, 2014. All Rights Reserved.
 * Copyright (c) Xerox Corporation, Codendi Team, 2001-2009. All rights reserved
 *
 * This file is a part of Tuleap.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Tracker_ report.
 * Set of criteria + set of Renderer to search and display artifacts
 */
class Tracker_Report extends Error implements Tracker_Dispatchable_Interface {
    
    const ACTION_SAVE    = 'report-save';
    const ACTION_SAVEAS  = 'report-saveas';
    const ACTION_REPLACE = 'report-replace';
    const ACTION_DELETE  = 'report-delete';
    const ACTION_SCOPE   = 'report-scope';
    const ACTION_DEFAULT = 'report-default';
    const ACTION_CLEANSESSION = 'clean-session';
    
    public $id;
    public $name;
    public $description;
    public $current_renderer_id;
    public $parent_report_id;
    public $user_id;
    public $group_id;
    public $is_default;
    public $tracker_id;
    public $is_query_displayed;
    public $updated_by;
    public $updated_at;
    
    public $renderers;
    public $criteria;

    /**
     * Constructor
     *
     * @param int     $id The id of the report
     * @param string  $name The name of the report
     * @param string  $description The description of the report
     * @param int     $current_renderer_id The current Renderer id to display
     * @param int     $parent_report_id The parent report if this report is temporary (null else)
     * @param int     $user_id The owner of the report (null if scope = project)
     * @param bool    $is_default true if the report is the default one
     * @param int     $tracker_id The id of the tracker to which this Tracker_Report is associated.
     */
    function __construct($id, $name, $description, $current_renderer_id, $parent_report_id, $user_id, $is_default, $tracker_id, $is_query_displayed, $updated_by, $updated_at) {
        parent::__construct();
        $this->id                  = $id;
        $this->name                = $name;
        $this->description         = $description;
        $this->current_renderer_id = $current_renderer_id;
        $this->parent_report_id    = $parent_report_id;
        $this->user_id             = $user_id;
        $this->is_default          = $is_default;
        $this->tracker_id          = $tracker_id;
        $this->is_query_displayed  = $is_query_displayed;
        $this->updated_by          = $updated_by;
        $this->updated_at          = $updated_at;
    }

    public function setProjectId($id) {
        $this->group_id = $id;
    }

    protected function getProjectId() {
        if (!$this->group_id) {
            $this->group_id = $this->tracker->getGroupId();
        }
        return $this->group_id;
    }

    public function registerInSession() {
        $this->report_session = new Tracker_Report_Session($this->id);
    }
    
    protected function getCriteriaDao() {
        return new Tracker_Report_CriteriaDao();
    }

    public function getCriteria() {
        $session_criteria = null;
        if (isset($this->report_session)) {
            $session_criteria = &$this->report_session->getCriteria();
        }
        
        $this->criteria = array();
        $ff = $this->getFormElementFactory();
        //there is previously stored
        if ($session_criteria) {
            $rank = 0;
            foreach ($session_criteria as $key => $value) {
                if ($value['is_removed'] == 0) {
                    $is_advanced = isset($value['is_advanced']) ? $value['is_advanced'] : 0 ;
                    if ($formElement = $ff->getFormElementById($key)) {
                        if ($formElement->userCanRead()) {
                            $formElement->setCriteriaValue( !empty($value['value']) ? $value['value']: '' ) ;
                            $this->criteria[$key] = new Tracker_Report_Criteria(
                                    0,
                                    $this,
                                    $formElement,
                                    $rank,
                                    $is_advanced
                            );
                            $rank++;
                        }
                    }
                }
            }
        } else {            
            //retrieve data from database
            foreach($this->getCriteriaDao()->searchByReportId($this->id) as $row) {
                if ($formElement = $ff->getFormElementFieldById($row['field_id'])) {
                    if ($formElement->userCanRead()) {
                        $this->criteria[$row['field_id']] = new Tracker_Report_Criteria(
                                $row['id'],
                                $this,
                                $formElement,
                                $row['rank'],
                                $row['is_advanced']
                        );
                        $criterion_value = $formElement->getCriteriaValue($this->criteria[$row['field_id']]);
                        $criterion_opts['is_advanced'] = $row['is_advanced'];     
                        if (isset($this->report_session)) {                   
                            $this->report_session->storeCriterion($row['field_id'], $criterion_value, $criterion_opts );
                        }
                    }
                }
            }
        }
        return $this->criteria;
    }
    
    public function getCriteriaFromDb() {
        $this->criteria = array();
        $ff = $this->getFormElementFactory();
        //retrieve data from database
        foreach($this->getCriteriaDao()->searchByReportId($this->id) as $row) {
            if ($formElement = $ff->getFormElementById($row['field_id'])) {
                if ($formElement->userCanRead()) {
                    $this->criteria[$row['field_id']] = new Tracker_Report_Criteria(
                            $row['id'],
                            $this,
                            $formElement,
                            $row['rank'],
                            $row['is_advanced']
                    );
                }
            }
        }
        return $this->criteria;
    }

    public function getFormElementFactory() {
        return Tracker_FormElementFactory::instance();
    }
    /**
     * Sets or adds a criterion to the global report search criteria list
     * @param integer $field_id criterion id to be added or set 
     * @return Tracker_Report_Criteria
     * @TODO refactor : must be renamed after addCriterion, and return the current criterion
     */
    protected function setCriteria($field_id) {
        $ff = $this->getFormElementFactory();
        $formElement = $ff->getFormElementById($field_id);
        $this->criteria[$field_id] = new Tracker_Report_Criteria(
                                0, 
                                $this, 
                                $formElement, 
                                0, 
                                0
                            );        
        return $this->criteria[$field_id];
    }

    protected $current_user;
    protected function getCurrentUser() {
        if (!$this->current_user) {
            $this->current_user = UserManager::instance()->getCurrentUser();
        }
        return $this->current_user;
    }

    protected $permissions_manager;
    private function getPermissionsManager() {
        if (!$this->permissions_manager) {
            $this->permissions_manager = PermissionsManager::instance();
        }
        return $this->permissions_manager;
    }

    protected $matching_ids;
    public function getMatchingIds($request = null, $use_data_from_db = false) {
        if (!$this->matching_ids) {
            $user = $this->getCurrentUser();
            if ($use_data_from_db) {
                $criteria = $this->getCriteriaFromDb();
            } else {
                $criteria = $this->getCriteria();
            }
            $this->matching_ids = $this->getMatchingIdsInDb($this->getDao(), $this->getPermissionsManager(), $this->getTracker(), $user, $criteria);

            $additional_criteria = $this->getAdditionalCriteria();
            $result              = array();
            $search_performed    = false;
            EventManager::instance()->processEvent(
                TRACKER_EVENT_REPORT_PROCESS_ADDITIONAL_QUERY,
                array(
                    'request'             => $request,
                    'result'              => &$result,
                    'search_performed'    => &$search_performed,
                    'tracker'             => $this->getTracker(),
                    'additional_criteria' => $additional_criteria
                )
            );
            if ($search_performed) {
                $joiner = new Tracker_Report_ResultJoiner();

                $this->matching_ids = $this->implodeMatchingIds(
                    $joiner->joinResults(
                        $this->getLastChangesetIdByArtifactId($this->matching_ids),
                        $result
                    )
                );
            }
        }

        return $this->matching_ids;
    }

    /**
     * Given the output of getMatchingIds() which returns an array containing 'artifacts ids' and  'Last changeset ids'
     * as two strings with comma separated values, this method would format such output to an array with artifactIds in keys
     * and last changeset ids in values.
     * @see Tracker_Report::getMatchingIds() for usage
     *
     * @return Array
     */
    private function getLastChangesetIdByArtifactId($matching_ids) {
        $artifact_ids       = explode(',', $matching_ids['id']);
        $last_changeset_ids = explode(',', $matching_ids['last_changeset_id']);

        $formatted_matching_ids = array();
        foreach ($artifact_ids as $key => $artifactId) {
            $formatted_matching_ids[$artifactId] = $last_changeset_ids[$key];
        }
        return $formatted_matching_ids;
    }

    /**
     * This method is the opposite of getLastChangesetIdByArtifactId().
     * Given an array with artifactIds in keys and lastChangesetIds on values it would return and array with two elements of type string
     * the first contains comma separated "artifactIds" and the second contains comma separated "lastChangesetIds".
     * @see Tracker_Report::getMatchingIds() for usage
     *
     * @param Array $formattedMatchingIds Matching Id's that will get converted in that format
     *
     * @return Array
     */
    private function implodeMatchingIds($formattedMatchingIds) {
        $matchingIds['id']                = '';
        $matchingIds['last_changeset_id'] = '';
        foreach ($formattedMatchingIds as $artifactId => $lastChangesetId) {
            $matchingIds['id']                .= $artifactId.',';
            $matchingIds['last_changeset_id'] .= $lastChangesetId.',';
        }
        if (substr($matchingIds['id'], -1) === ',') {
            $matchingIds['id'] = substr($matchingIds['id'], 0, -1);
        }
        if (substr($matchingIds['last_changeset_id'], -1) === ',') {
            $matchingIds['last_changeset_id'] = substr($matchingIds['last_changeset_id'], 0, -1);
        }
        return $matchingIds;
    }

    protected function getMatchingIdsInDb(DataAccessObject $dao, PermissionsManager $permissionManager, Tracker $tracker, PFUser $user, array $criteria) {
        $dump_criteria = array();
        foreach ($criteria as $c) {
            $dump_criteria[$c->field->getName()] = $c->field->getCriteriaValue($c);
        }
        $dao->logStart(__METHOD__, json_encode(array(
            'user'     => $user->getUserName(),
            'project'  => $tracker->getGroupId(), 
            'query'    => $dump_criteria,
            'trackers' => array($tracker->getId()),
        )));
        
        $matching_ids = array();
        
        $group_id             = $tracker->getGroupId();
        $permissions          = $permissionManager->getPermissionsAndUgroupsByObjectid($tracker->getId());
        $contributor_field    = $tracker->getContributorField();
        $contributor_field_id = $contributor_field ? $contributor_field->getId() : null;
        
        $additional_from  = array();
        $additional_where = array();
        foreach($criteria as $c) {
            if ($f = $c->getFrom()) {
                $additional_from[]  = $f;
            }
            
            if ($w = $c->getWhere()) {
                $additional_where[] = $w;
            }
        }
        $matching_ids['id']                = '';
        $matching_ids['last_changeset_id'] = '';
        $matching_ids_result = $dao->searchMatchingIds($group_id, $tracker->getId(), $additional_from, $additional_where, $user, $permissions, $contributor_field_id);
        if ($matching_ids_result) {
            $matching_ids = $matching_ids_result->getRow();
            if ($matching_ids) {
                if (substr($matching_ids['id'], -1) === ',') {
                    $matching_ids['id'] = substr($matching_ids['id'], 0, -1);
                }
                if (substr($matching_ids['last_changeset_id'], -1) === ',') {
                    $matching_ids['last_changeset_id'] = substr($matching_ids['last_changeset_id'], 0, -1);
                }
            }
        }
        
        $nb_matching = $matching_ids['id'] ? substr_count($matching_ids['id'], ',') + 1 : 0;
        $dao->logEnd(__METHOD__, $nb_matching);
        
        return $matching_ids;
    }
    
    /**
     * @return boolean true if the report has been modified since the last checkout
     */
    public function isObsolete() {
        return isset($this->report_session) && $this->updated_at && ($this->report_session->get('checkout_date') < $this->updated_at);
    }
    
    /**
     * @return string html the user who has modified the report. Or false if the report has not been modified
     */
    public function getLastUpdaterUserName() {
        if ($this->isObsolete()) {
            return UserHelper::instance()->getLinkOnUserFromUserId($this->updated_by);
        }
        return '';
    }

    protected function displayHeader(Tracker_IFetchTrackerSwitcher $layout, $request, $current_user, $report_can_be_modified) {
        $header_builder = new Tracker_Report_HeaderRenderer(
            Tracker_ReportFactory::instance(),
            Codendi_HTMLPurifier::instance(),
            $this->getTemplateRenderer()
        );
        $header_builder->displayHeader($layout, $request, $current_user, $this, $report_can_be_modified);
    }
    
    public function nbPublicReport($reports) {
        $i = 0;
        foreach ($reports as $report) {
            if ($report->user_id == null) {
              $i++;
            }
        }
        return $i;
    }
    
    public function fetchDisplayQuery(array $criteria, array $additional_criteria, $report_can_be_modified, PFUser $current_user) {
        $hp              = Codendi_HTMLPurifier::instance();
        $user_can_update = $this->userCanUpdate($current_user);

        $html = '';
        
        $html .= '<div id="tracker_report_query" data-report-id="'.$this->id.'">';
        $html .= '<form action="" method="POST" id="tracker_report_query_form">';
        $html .= '<input type="hidden" name="report" value="' . $this->id . '" />';
        $id = 'tracker_report_query_' . $this->id;
        $html .= '<h4 class="backlog-planning-search-title ' . Toggler::getClassname($id, $this->is_query_displayed ? true : false) . '" id="' . $id . '">';

        //  Query title
        $html .= $GLOBALS['Language']->getText('plugin_tracker_report', 'search').'</h4>';
        $used = array();
        $criteria_fetched = array();
        foreach ($criteria as $criterion) {
            if ($criterion->field->isUsed()) {
                $li = '<li id="tracker_report_crit_' . $criterion->field->getId() . '">';
                if ($user_can_update) {
                    $li .= $criterion->fetch();
                } else {
                    $li .= $criterion->fetchWithoutExpandFunctionnality();
                }
                $li .= '</li>';
                $criteria_fetched[] = $li;
                $used[$criterion->field->getId()] = $criterion->field;
            }
        }
        if ($report_can_be_modified && $user_can_update) {
            $html .= '<div class="pull-right">';
            $html .= $this->getAddCriteriaDropdown($used);
            $html .= '</div>';
        }

        $array_of_html_criteria = array();
        EventManager::instance()->processEvent(
            TRACKER_EVENT_REPORT_DISPLAY_ADDITIONAL_CRITERIA,
            array(
                'array_of_html_criteria' => &$array_of_html_criteria,
                'tracker'                => $this->getTracker(),
                'additional_criteria'    => $additional_criteria,
                'user'                   => $current_user,
            )
        );
        foreach ($array_of_html_criteria as $additional_criteria) {
            $criteria_fetched[] = '<li>'. $additional_criteria .'</li>';
        }
        $html .= '<ul id="tracker_query">' . implode('', $criteria_fetched).'</ul>';

        $html .= '<div align="center">';
        $html .= '<button type="submit" name="tracker_query_submit" class="btn btn-primary">';
        $html .= '<i class="icon-search"></i> ';
        $html .= $GLOBALS['Language']->getText('global', 'btn_search');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }

    private function getAddCriteriaDropdown($used) {
        $add_criteria_presenter = new Templating_Presenter_ButtonDropdownsMini(
            'tracker_report_add_criteria_dropdown',
            $GLOBALS['Language']->getText('plugin_tracker_report', 'toggle_criteria'),
            $this->getFieldsAsDropdownOptions('tracker_report_add_criterion', $used)
        );
        $add_criteria_presenter->setIcon('icon-eye-close');

        return $this->getTemplateRenderer()->renderToString('button_dropdowns',  $add_criteria_presenter);
    }

    public function getFieldsAsDropdownOptions($id_prefix, array $used) {
        $fields_for_criteria = array();
        $fields_for_sort = array();
        foreach($this->getFormElementFactory()->getFields($this->getTracker()) as $field) {
            if ($field->userCanRead() && $field->isUsed()) {
                $fields_for_criteria[$field->getId()] = $field;
                $fields_for_sort[$field->getId()] = strtolower($field->getLabel());
            }
        }
        asort($fields_for_sort);
        $criteria_options = array();
        foreach ($fields_for_sort as $id => $nop) {
            $option = new Templating_Presenter_ButtonDropdownsOption(
                $id_prefix.'_'.$id,
                $fields_for_criteria[$id]->getLabel(),
                isset($used[$id]),
                '#'
            );
            $option->setLiParameters(
                array(
                    'data-field-id'      => $id,
                    'data-field-is-used' => intval(isset($used[$id])),
                )
            );
            $criteria_options[] = $option;
        }
        return $criteria_options;
    }

    public function getTemplateRenderer() {
        return TemplateRendererFactory::build()->getRenderer(
            array(
                TRACKER_TEMPLATE_DIR.'/report',
                Config::get('codendi_dir').'/src/templates/common'
            )
        );
    }

    public function display(Tracker_IDisplayTrackerLayout $layout, $request, $current_user) {
        
        $link_artifact_id       = (int)$request->get('link-artifact-id');
        $report_can_be_modified = !$link_artifact_id;
        
        $hp = Codendi_HTMLPurifier::instance();
        $current_user = UserManager::instance()->getCurrentUser();
        $renderer_preference_key = 'tracker_'. $this->tracker_id .'_report_'. $this->id .'_last_renderer';
        
        if ($link_artifact_id) {
            //Store in user preferences
            if ($current_user->getPreference('tracker_'. $this->tracker_id .'_last_report') != $this->id) {
                $current_user->setPreference('tracker_'. $this->tracker_id .'_last_report', $this->id);
            }
        }
        
        $renderers = $this->getRenderers();
        $current_renderer = null;
        //search for the current renderer
        if (is_array($request->get('renderer'))) {
            list($renderer_id, ) = each($request->get('renderer'));
            if (isset($renderers[$renderer_id])) {
                $current_renderer = $renderers[$renderer_id];
            }
        }
        if (!$current_renderer) {
            foreach($renderers as $r) {
                if (!$current_renderer || ($request->get('renderer') == $r->id) 
                                       || (!$request->get('renderer') && $r->id == $this->current_renderer_id)
                                       || (!$request->get('renderer') && $r->id == $current_user->getPreference($renderer_preference_key))) {
                    $current_renderer = $r;
                }
            }
        }
        if (!$current_renderer) {
            list(,$current_renderer) = each($renderers);
        }
        if ($current_renderer && $current_user->getPreference($renderer_preference_key) != $current_renderer->id) {
            $current_user->setPreference($renderer_preference_key, $current_renderer->id);
        }
        
        // We need an ArtifactLinkable renderer for ArtifactLink
        if ($link_artifact_id && !is_a($current_renderer, 'Tracker_Report_Renderer_ArtifactLinkable')) {
            foreach($renderers as $r) {
                if (is_a($r, 'Tracker_Report_Renderer_ArtifactLinkable')) {
                    $current_renderer = $r;
                    break;
                }
            }
        }
        if ($request->get('only-renderer')) {
            echo $current_renderer->fetch($this->getMatchingIds($request, false), $request, $report_can_be_modified, $current_user);
        } else {
            $this->displayHeader($layout, $request, $current_user, $report_can_be_modified);
            
            $html = '';
            
            //Display Criteria
            $registered_criteria = array();
            $this->getCriteria();
            $session_criteria = $this->report_session->getCriteria();
            if ($session_criteria) {
                foreach ($session_criteria as $key => $session_criterion) {
                    if (!empty($session_criterion['is_removed'])) {
                        continue;
                    }
                    if (!empty($this->criteria[$key])) {
                        $registered_criteria[] = $this->criteria[$key];
                    }
                }
            }
            $additional_criteria = $this->getAdditionalCriteria();

            $html .= $this->fetchDisplayQuery($registered_criteria, $additional_criteria, $report_can_be_modified, $current_user);
            
            //Display Renderers
            $html .= '<div>';
            $html .= '<ul id="tracker_report_renderers" class="nav nav-tabs">';
            
            foreach($renderers as $r) {
                $active = $r->id == $current_renderer->id ? 'tracker_report_renderers-current active dropdown' : '';
                if ($active || !$link_artifact_id || is_a($r, 'Tracker_Report_Renderer_ArtifactLinkable')) {
                    $parameters = array(
                        'report'   => $this->id,
                        'renderer' => $r->id
                    );
                    if ($request->existAndNonEmpty('pv')) {
                        $parameters['pv'] = (int)$request->get('pv');
                    }
                    if ($link_artifact_id) {
                        $parameters['link-artifact-id'] = (int)$link_artifact_id;
                        $parameters['only-renderer']    = 1;
                    }

                    $url = $active ? '#' : '?'. http_build_query($parameters);
                    $html .= '<li id="tracker_report_renderer_'. $r->id .'"
                                  class="'. $active .'
                                            tracker_report_renderer_tab
                                            tracker_report_renderer_tab_'. $r->getType() .'">
                              <a href="'. $url .'" title="'.  $hp->purify($r->description, CODENDI_PURIFIER_CONVERT_HTML)  .'" '. ($active ? 'class="dropdown-toggle" data-toggle="dropdown"' : '') .'>';
                    $html .= '<input type="hidden" name="tracker_report_renderer_rank" value="'.(int)$r->rank.'" />';
                    $html .= '<i class="'. $r->getIcon() .'"></i>';
                    $html .= ' '. $hp->purify($r->name, CODENDI_PURIFIER_CONVERT_HTML) ;
                    if ($active) {
                        //Check that user can update the renderer
                        if ($report_can_be_modified && $this->userCanUpdate($current_user)) {
                            $html .= ' <b class="caret" id="tracker_renderer_updater_handle"></b>';
                        }
                    }
                    $html .= '</a>';
                    if ($report_can_be_modified && $this->userCanUpdate($current_user)) {
                        $html .= '<div class="dropdown-menu">'. $this->fetchUpdateRendererForm($r) .'</div>';
                    }
                    $html .= '</li>';
                }
            }
            
            if ($report_can_be_modified && $this->userCanUpdate($current_user)) {
                $html .= '<li class="tracker_report_renderers-add dropdown">
                    <a id="tracker_renderer_add_handle"
                       href="#"
                       class="dropdown-toggle"
                       data-toggle="dropdown">';
                $html .=  '<i class="icon-plus"></i>' ;
                $html .= '</a>';
                $html .= '<div class="dropdown-menu">'. $this->fetchAddRendererForm($current_renderer) .'</div>';
                $html .= '</li>';
            }
            
            $html .= '</ul>';
            
    
            if ($current_renderer) {
                $html .= '<div class="tracker_report_renderer" id="tracker_report_renderer_current" data-renderer-id="'. $current_renderer->getId() .'">';

                if ($current_renderer->description) {
                    $html .= '<p class="tracker_report_renderer_description">'. $hp->purify($current_renderer->description, CODENDI_PURIFIER_BASIC) .'</p>';
                }

                //  Options menu
                if ($report_can_be_modified && ($options = $current_renderer->getOptionsMenuItems())) {
                    $html .= '<div id="tracker_renderer_options">';
                    $html .= implode(' ', $options);
                    $html .= '</div>';
                }

                //Warning about Full text in Tracker Report...
                $fts_warning = '';
                $params = array('html' => &$fts_warning, 'request' => $request, 'group_id' => $this->getProjectId());
                EventManager::instance()->processEvent('tracker_report_followup_warning', $params);
                $html .= $fts_warning;

                $html .= $current_renderer->fetch($this->getMatchingIds($request, false), $request, $report_can_be_modified, $current_user);
                $html .= '</div>';
            }
            $html .= '</div>';
            echo $html;

            if ($report_can_be_modified) {
                $this->getTracker()->displayFooter($layout);
                exit();
            }
        }
    }

    public function getRenderers() {
        return Tracker_Report_RendererFactory::instance()->getReportRenderersByReport($this);
    }    

    protected function orderRenderersByRank($renderers) {
        $array_rank = array();
        foreach($renderers as $field_id => $properties) {
            $array_rank[$field_id] = $properties->rank;
        }        
        asort($array_rank);
        $renderers_sort = array();
        foreach ($array_rank as $id => $rank) {
            $renderers_sort[$id] = $renderers[$id];
        }
        return  $renderers_sort;
    }

    protected function getRendererFactory() {
        return Tracker_Report_RendererFactory::instance();
    }
    
    protected function _fetchAddCriteria($used) {
        $html = '';
        
        $options = '';
        foreach($this->getTracker()->getFormElements() as $formElement) {
            if ($formElement->userCanRead()) {
                $options .= $formElement->fetchAddCriteria($used);
            }
        }
        if ($options) {
            $html .= '<select name="add_criteria" id="tracker_report_add_criteria" autocomplete="off">';
            $html .= '<option selected="selected" value="">'. '-- '.$GLOBALS['Language']->getText('plugin_tracker_report', 'toggle_criteria').'</option>';
            $html .= $options;
            $html .= '</select>';
        }
        return $html;
    }
    
    /**
     * Say if the report is public
     *
     * @return bool
     */
    public function isPublic() {
        return empty($this->user_id);
    }
    
    /**
     * Only owners of a report can update it.
     * owner = report->user_id
     * or if null, owner = tracker admin or site admins
     * @param PFUser $user the user who wants to update the report
     * @return boolean
     */
    public function userCanUpdate($user) {
        if (! $this->isBelongingToATracker()) {
            return false;
        }

        if ($this->user_id) {
            return $this->user_id == $user->getId();
        } else {
            $tracker = $this->getTracker();
            return $user->isSuperUser() || $tracker->userIsAdmin($user);
        }
    }

    private function isBelongingToATracker() {
        return $this->getTracker() != null;
    }

    protected $tracker;
    public function getTracker() {
        if (!$this->tracker) {
            $this->tracker = TrackerFactory::instance()->getTrackerById($this->tracker_id);
        }
        return $this->tracker;
    }

    public function setTracker(Tracker $tracker) {
        $this->tracker    = $tracker;
        $this->tracker_id = $tracker->getId();
    }

    /**
     * hide or show the criteria
     */
    public function toggleQueryDisplay() {
        $this->is_query_displayed = !$this->is_query_displayed;
        return $this;
    }
    
    /**
     * Remove a formElement from criteria
     * @param int $formElement_id the formElement used for the criteria
     */
    public function removeCriteria($formElement_id) {
        $criteria = $this->getCriteria();
        if (isset($criteria[$formElement_id])) {
            if ($this->getCriteriaDao()->delete($this->id, $formElement_id)) {
                $criteria[$formElement_id]->delete();
                unset($criteria[$formElement_id]);
            }
        }
        return $this;
    }
    
    /**
     * Add a criteria
     *
     * @param Tracker_Report_Criteria the formElement used for the criteria
     *
     * @return int the id of the new criteria
     */
    public function addCriteria( Tracker_Report_Criteria $criteria ) {
        $id = $this->getCriteriaDao()->create($this->id, $criteria->field->id, $criteria->is_advanced);
        return $id;
    }

    public function deleteAllCriteria() {
        $this->getCriteriaDao()->deleteAll($this->id);
    }
    
    /**
     * Toggle the state 'is_advanced' of a criteria
     * @param int $formElement_id the formElement used for the criteria
     */
    public function toggleAdvancedCriterion($formElement_id) {
        $advanced = 1;
        $session_criterion = $this->report_session->getCriterion($formElement_id);
        if ( !empty($session_criterion['is_advanced']) ) {
            $advanced = 0;
        }
        $this->report_session->updateCriterion($formElement_id, '', array('is_advanced'=>$advanced));
        return $this;
    }
    
    /**
     * Store the criteria value
     * NOTICE : if a criterion does not exist it is not created
     * @param array $criteria_values
     */
    public function updateCriteriaValues($criteria_values) {
        $ff = $this->getFormElementFactory();
        foreach($criteria_values as $formElement_id => $new_value) {
            $session_criterion = $this->report_session->getCriterion($formElement_id);
            if ( $session_criterion ) {
                if ($field = $ff->getFormElementById($formElement_id)) {
                    $this->report_session->storeCriterion($formElement_id, $field->getFormattedCriteriaValue($new_value));
                }
            }
        }        
    }

    public function updateAdditionalCriteriaValues($additional_criteria_values) {
        foreach($additional_criteria_values as $key => $new_value) {
            $additional_criterion = new Tracker_Report_AdditionalCriterion($key, $new_value);
            $this->report_session->storeAdditionalCriterion($additional_criterion);
        }
    }

    /**
     * Process the request for the specified renderer
     * @param int $renderer_id
     * @param Request $request
     * @return ReportRenderer
     */
    public function processRendererRequest($renderer_id, Tracker_IDisplayTrackerLayout $layout, $request, $current_user, $store_in_session = true) {
        $rrf = Tracker_Report_RendererFactory::instance();
        if ($renderer = $rrf->getReportRendererByReportAndId($this, $renderer_id, $store_in_session)) {
            $renderer->process($layout, $request, $current_user);
        }
    }
    
    /**
     * Delete a renderer from the report
     * @param mixed the renderer to remove (Tracker_Report_Renderer or the id as int)
     */
    public function deleteRenderer($renderer) {
        $rrf = Tracker_Report_RendererFactory::instance();
        if (!is_a($renderer, 'Tracker_Report_Renderer')) {
            $renderer_id = (int)$renderer;
            $renderer = $rrf->getReportRendererByReportAndId($this, $renderer_id);
        }
        if ($renderer) {
            $renderer_id = $renderer->id;
            $renderer->delete();
            $rrf->delete($renderer_id);
        }
        return $this;
    }
    
    /**
     * Move a renderer at a specific position
     *
     * @param mixed $renderer the renderer to remove (Tracker_Report_Renderer or the id as int)
     * @param int   $position the new position
     */
    public function moveRenderer($renderer, $position) {
        $rrf = Tracker_Report_RendererFactory::instance();
        if (!is_a($renderer, 'Tracker_Report_Renderer')) {
            $renderer_id = (int)$renderer;
            $renderer = $rrf->getReportRendererByReportAndId($this, $renderer_id);
        }
        if ($renderer) {
            $rrf->move($renderer->id, $this, $position);
        }
        return $this;
    }
    
    /**
     * Add a new renderer to the report
     *
     * @param string $name
     * @param string $description
     *
     * @return int the id of the new renderer
     */
    public function addRenderer($name, $description, $type) {
        $rrf = Tracker_Report_RendererFactory::instance();
        return $rrf->create($this, $name, $description, $type);
    }

    public function addRendererInSession($name, $description, $type) {
        $rrf = Tracker_Report_RendererFactory::instance();
        return $rrf->createInSession($this, $name, $description, $type);
    }

    
    public function process(Tracker_IDisplayTrackerLayout $layout, $request, $current_user) {
        if ($this->isObsolete()) {
            header('X-Codendi-Tracker-Report-IsObsolete: '. $this->getLastUpdaterUserName());
        }
        $hp = Codendi_HTMLPurifier::instance();
        $tracker = $this->getTracker();
        switch ($request->get('func')) {
            case 'display-masschange-form':
                if ($tracker->userIsAdmin($current_user)) {
                    $masschange_aids = array();
                    $renderer_table  =  $request->get('renderer_table');

                    if ( !empty($renderer_table['masschange_checked']) ) {                
                        $masschange_aids = $request->get('masschange_aids');                
                    } else if (!empty($renderer_table['masschange_all'])) {
                        $masschange_aids = $request->get('masschange_aids_all');                
                    }
                    
                    if( empty($masschange_aids) ) {
                        $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker_masschange_detail', 'no_items_selected'));
                        $GLOBALS['Response']->redirect(TRACKER_BASE_URL.'/?tracker='. $tracker->getId());
                    }
                    $tracker->displayMasschangeForm($layout, $masschange_aids);
                } else {
                    $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker_admin', 'access_denied'));
                    $GLOBALS['Response']->redirect(TRACKER_BASE_URL.'/?tracker='. $tracker->getId());
                }
                break;
             case 'update-masschange-aids':
                if ($tracker->userIsAdmin($current_user)) {
                    $masschange_aids = $request->get('masschange_aids');
                    if ( empty($masschange_aids) ) {
                        $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker_masschange_detail', 'no_items_selected'));
                        $GLOBALS['Response']->redirect(TRACKER_BASE_URL.'/?tracker='. $tracker->getId());
                    }
                    $masschange_data = $request->get('artifact');
                    if ( empty($masschange_data) ) {
                        $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker_masschange_detail', 'no_items_selected'));
                        $GLOBALS['Response']->redirect(TRACKER_BASE_URL.'/?tracker='. $tracker->getId());
                    }
                    $send_notifications = false; // by default, don't send notifications.
                    if ($request->exist('notify')) {
                        if ($request->get('notify') == 'ok') {
                            $send_notifications = true;
                        }
                    }
                    $comment_format = $request->get('comment_formatmass_change');
                    $tracker->updateArtifactsMasschange($current_user, $masschange_aids, $masschange_data, $request->get('artifact_masschange_followup_comment'), $send_notifications, $comment_format);
                    $GLOBALS['Response']->redirect(TRACKER_BASE_URL.'/?tracker='. $tracker->getId());
                } else {
                    $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker_admin', 'access_denied'));
                    $GLOBALS['Response']->redirect(TRACKER_BASE_URL.'/?tracker='. $this->getId());
                }
                break;
           case 'remove-criteria':
                if ($this->userCanUpdate($current_user) && $request->get('field')) {
                    $this->report_session->removeCriterion($request->get('field'));
                    $this->report_session->setHasChanged();
                }
                break;
            case 'add-criteria':
                if ($this->userCanUpdate($current_user) && $request->get('field')) {
                    //TODO: make sure that the formElement exists and the user can read it
                    if ($request->isAjax()) {
                        $criteria = $this->getCriteria();
                        $field_id = $request->get('field');
                        $this->setCriteria($field_id);
                        $this->report_session->storeCriterion($field_id, '', array('is_advanced'=>0));
                        $this->report_session->setHasChanged();
                        echo $this->criteria[$field_id]->fetch();
                    }
                }
                break;
            case 'toggle-advanced':
                if ($this->userCanUpdate($current_user) && $request->get('field')) {
                    $this->toggleAdvancedCriterion($request->get('field'));
                    $this->report_session->setHasChanged();
                    if ($request->isAjax()) {
                        $criteria = $this->getCriteria();
                        if (isset($criteria[$request->get('field')])) {
                            echo $criteria[$request->get('field')]->fetch();
                        }
                    }
                }
                break;
            case self::ACTION_CLEANSESSION:
                $this->report_session->clean();
                $GLOBALS['Response']->redirect('?'. http_build_query(array(
                        'tracker'   => $this->tracker_id
                )));
                break;
            case 'renderer':
                if (/* $this->userCanUpdate($current_user) &&  // NTY: user should access to charts even if they can't update the renderer */ $request->get('renderer')) {
                    $store_in_session = true;
                    if ($request->exist('store_in_session')) {
                        $store_in_session = (bool)$request->get('store_in_session');
                    }
                    $this->processRendererRequest($request->get('renderer'), $layout, $request, $current_user, $store_in_session);
                }
                break;
            case 'rename-renderer':
                if ($request->get('new_name') == '') {
                    $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker_report','renderer_name_mandatory'));
                } else if ($this->userCanUpdate($current_user) && (int)$request->get('renderer') && trim($request->get('new_name'))) { 
                    $this->report_session->renameRenderer((int)$request->get('renderer'), trim($request->get('new_name')), trim($request->get('new_description')));
                    $this->report_session->setHasChanged();                    
                }
                $GLOBALS['Response']->redirect('?'. http_build_query(array(
                                                            'report'   => $this->id
                                                            )));
                break;
            case 'delete-renderer':
                if ($this->userCanUpdate($current_user) && (int)$request->get('renderer')) {
                    $this->report_session->removeRenderer((int)$request->get('renderer'));
                    $this->report_session->setHasChanged();
                    $GLOBALS['Response']->redirect('?'. http_build_query(array(
                                                            'report'   => $this->id
                                                            )));
                }
                break;
            case 'move-renderer':
                if ($this->userCanUpdate($current_user) && (int)$request->get('renderer')) {
                    if ($request->isAjax()) {
                        $this->report_session->moveRenderer($request->get('tracker_report_renderers'));
                        $this->report_session->setHasChanged();
                    } else {
                        if ( $request->get('move-renderer-direction')) {                            
                            $this->moveRenderer((int)$request->get('renderer'), $request->get('move-renderer-direction'));
                            $GLOBALS['Response']->redirect('?'. http_build_query(array(
                                                                    'report'   => $this->id
                                                                    )));
                        }
                    }
                }
                break;
            case 'add-renderer':
                $new_name        = trim($request->get('new_name'));
                $new_description = trim($request->get('new_description'));
                $new_type        = trim($request->get('new_type'));
                if ($this->userCanUpdate($current_user) && $new_name) {                    
                    $new_renderer_id = $this->addRendererInSession($new_name, $new_description, $new_type);
                    $GLOBALS['Response']->redirect('?'. http_build_query(array(
                                                            'report'   => $this->id,
                                                            'renderer' => $new_renderer_id ? $new_renderer_id : ''
                                                            )));
                }
                break;
            case self::ACTION_SAVE:
                Tracker_ReportFactory::instance()->save($this);
                $this->saveCriteria();
                $this->saveAdditionalCriteria();
                $this->saveRenderers();
                //Clean session
                $this->report_session->cleanNamespace();
                
                $GLOBALS['Response']->addFeedback('info', '<a href="?report='. $this->id .'">'. $hp->purify($this->name, CODENDI_PURIFIER_CONVERT_HTML) .'</a> has been saved.', CODENDI_PURIFIER_DISABLED);
                $GLOBALS['Response']->redirect('?'. http_build_query(array(
                    'report'   => $this->id
                )));
                break;
            case self::ACTION_SAVEAS:
                $redirect_to_report_id = $this->id;
                $report_copy_name = trim($request->get('report_copy_name'));
                if ($report_copy_name) {
                    $new_report = Tracker_ReportFactory::instance()->duplicateReportSkeleton($this, $this->tracker_id, $current_user->getId());
                    //Set the name
                    $new_report->name = $report_copy_name;
                    //The new report is individual
                    $new_report->user_id = $current_user->getId();
                    Tracker_ReportFactory::instance()->save($new_report);
                    $GLOBALS['Response']->addFeedback('info', '<a href="?report='. $new_report->id .'">'. $hp->purify($new_report->name, CODENDI_PURIFIER_CONVERT_HTML) .'</a> has been created.', CODENDI_PURIFIER_DISABLED);
                    $redirect_to_report_id = $new_report->id;
                    //copy parent tracker session content
                    $this->report_session->copy($this->id, $redirect_to_report_id);
                    //clean current session namespace
                    $this->report_session->cleanNamespace();
                    //save session content into db
                    $new_report->saveCriteria();
                    $new_report->saveAdditionalCriteria();
                    $new_report->saveRenderers();
                    $new_report->report_session->cleanNamespace();
                } else {
                    $GLOBALS['Response']->addFeedback('error', 'Invalid copy name', CODENDI_PURIFIER_DISABLED);
                }


                $GLOBALS['Response']->redirect('?'. http_build_query(array(
                    'report'   => $redirect_to_report_id
                )));
                break;
            case self::ACTION_DELETE:
                $this->delete();
                $GLOBALS['Response']->redirect('?'. http_build_query(array(
                    'tracker'   => $this->tracker_id
                )));
                break;
            case self::ACTION_SCOPE:
                if ($this->getTracker()->userIsAdmin($current_user) && (!$this->user_id || $this->user_id == $current_user->getId())) {
                    if ($request->exist('report_scope_public')) {
                        $old_user_id = $this->user_id;
                        if ($request->get('report_scope_public') && $this->user_id == $current_user->getId()) {
                            $this->user_id = null;
                        } else if (!$request->get('report_scope_public') && !$this->user_id) {
                            $this->user_id = $current_user->getId();
                        }
                        if ($this->user_id != $old_user_id) {
                            Tracker_ReportFactory::instance()->save($this);
                        }
                    }
                }
            case self::ACTION_DEFAULT:
                if ($this->getTracker()->userIsAdmin($current_user)) {
                    if ($request->exist('report_default')) {
                        $old_user_id = $this->user_id;
                        if ($request->get('report_default')) {
                            $this->is_default = '1';
                        } else {
                            $this->is_default = '0';
                        }
                    }
                    $this->setDefaultReport();
                    $GLOBALS['Response']->redirect('?'. http_build_query(array(
                        'report'   => $this->id
                    )));
                    break;
                }
            default:
                if ($request->exist('tracker_query_submit') && is_array($request->get('criteria'))) {
                    $criteria_values = $request->get('criteria');
                    $this->updateCriteriaValues($criteria_values);

                    $additional_criteria_values = $request->get('additional_criteria');
                    if (!empty($additional_criteria_values)) {
                        $this->updateAdditionalCriteriaValues($additional_criteria_values);
                    }
                }
                $this->display($layout, $request, $current_user);
                break;
        }
    }
    
    public function setDefaultReport() {
        $default_report = Tracker_ReportFactory::instance()->getDefaultReportByTrackerId($this->tracker_id);
        if ($default_report) {
            $default_report->is_default = '0';
            Tracker_ReportFactory::instance()->save($default_report);
        }
        Tracker_ReportFactory::instance()->save($this);
        
    }
    /**
     * NOTICE: make sure you are in the correct session namespace
     */
    public function saveCriteria() {
        //populate $this->criteria
        $this->getCriteria();
        //Delete criteria value
        foreach($this->criteria as $c) {
            if ($c->field->getCriteriaValue($c)) {
                $c->field->delete($c->id);
            }
        }
        //Delete criteria in the db
        $this->deleteAllCriteria();

        $session_criteria = $this->report_session->getCriteria();
        if (is_array($session_criteria)) {
            foreach($session_criteria as $key=>$session_criterion) {
                if ( !empty($session_criterion['is_removed'])) {
                    continue;
                }
                $c  = $this->criteria[$key];
                $id = $this->addCriteria($c);
                $c->setId($id);
                $c->updateValue($session_criterion['value']);
            }
        }
    }

    public function saveAdditionalCriteria() {
        $additional_criteria = $this->getAdditionalCriteria();
        EventManager::instance()->processEvent(
            TRACKER_EVENT_REPORT_SAVE_ADDITIONAL_CRITERIA,
            array(
                'additional_criteria'    => $additional_criteria,
                'report'                 => $this,
            )
        );
    }

    /**
     * Save report renderers
     * NOTICE: make sure you are in the correct session namespace
     *
     * @return void
     */
    public function saveRenderers() {
        $rrf = Tracker_Report_RendererFactory::instance();
        
        //Get the renderers in the session and in the db
        $renderers_session = $this->getRenderers();
        $renderers_db      = $rrf->getReportRenderersByReportFromDb($this);
        
        //Delete renderers in db if they are deleted in the session
        foreach ($renderers_db as $renderer_db_key => $renderer_db) {
            if ( ! isset($renderers_session[$renderer_db_key]) ) {
                $this->deleteRenderer($renderer_db_key);
            }
        }
        
        //Create or update renderers in db
        if(is_array($renderers_session)) {
            foreach ($renderers_session as $renderer_key => $renderer) {
                if( ! isset($renderers_db[$renderer_key]) ) {
                    // this is a new renderer
                    $renderer->create();
                } else {
                    // this is an old renderer
                    $rrf->save($renderer);
                    $renderer->update();
                }
            }
        }
    }    
    
    /**
     * Delete the report and its renderers
     */
    protected function delete() {
        //Delete user preferences
        $dao = new UserPreferencesDao();
        $dao->deleteByPreferenceNameAndValue('tracker_'. $this->tracker_id .'_last_report', $this->id);
        
        //Delete criteria
        foreach($this->getCriteria() as $criteria) {
            $this->removeCriteria($criteria->field->id);
        }
        
        //Delete renderers
        foreach($this->getRenderers() as $renderer) {
            $this->deleteRenderer($renderer);
        }
       
        //clean session
        $this->report_session->cleanNamespace();

        //Delete me
        return Tracker_ReportFactory::instance()->delete($this->id);
    }
    
    public function duplicate($from_report, $formElement_mapping) {
        //Duplicate criteria
        Tracker_Report_CriteriaFactory::instance()->duplicate($from_report, $this, $formElement_mapping);
        
        //Duplicate renderers
        Tracker_Report_RendererFactory::instance()->duplicate($from_report, $this, $formElement_mapping);
    }
    
    /**
     * Transforms Report into a SimpleXMLElement
     * 
     * @param SimpleXMLElement $root the node to which the Report is attached (passed by reference)
     */
    public function exportToXml(SimpleXMLElement $roott, $xmlMapping) {
        $root = $roott->addChild('report');
        // if old ids are important, modify code here 
        if (false) {
            $root->addAttribute('id', $this->id);
            $root->addAttribute('tracker_id', $this->tracker_id);
            $root->addAttribute('current_renderer_id', $this->current_renderer_id);
            $root->addAttribute('user_id', $this->user_id);
            $root->addAttribute('parent_report_id', $this->parent_report_id);
        }
        // only add if different from default values
        if (!$this->is_default) {
            $root->addAttribute('is_default', $this->is_default);
        }
        if (!$this->is_query_displayed) {
            $root->addAttribute('is_query_displayed', $this->is_query_displayed);
        }
        $root->addChild('name', $this->name);
        // only add if not empty
        if ($this->description) {
            $root->addChild('description', $this->description);
        }
        $child = $root->addChild('criterias');
        foreach($this->getCriteria() as $criteria) {
            $grandchild = $child->addChild('criteria');
            $criteria->exportToXML($grandchild, $xmlMapping);
        }
        $child = $root->addChild('renderers');
        foreach($this->getRenderers() as $renderer) {
            $grandchild = $child->addChild('renderer');
            $renderer->exportToXML($grandchild, $xmlMapping);
        }
    }

    /**
     * Convert the current report to its SOAP representation
     *
     * @return Array
     */
    public function exportToSoap() {
        return array(
            'id'          => (int)$this->id,
            'name'        => (string)$this->name,
            'description' => (string)$this->description,
            'user_id'     => (int)$this->user_id,
            'is_default'  => (bool)$this->is_default,
        );
    }

    protected $dao;
    /**
     * @return Tracker_ReportDao
     */
    public function getDao() {
        if (!$this->dao) {
            $this->dao = new Tracker_ReportDao();
        }
        return $this->dao;
    }

    public function getId() {
        return $this->id;
    }

    public function getName() {
        return $this->name;
    }

    private function getAdditionalCriteria() {
        $session_additional_criteria = null;
        if (isset($this->report_session)) {
            $session_additional_criteria = &$this->report_session->getAdditionalCriteria();
        }

        $additional_criteria = array();
        if ($session_additional_criteria) {
            foreach ($session_additional_criteria as $key => $additional_criterion_value) {
                $additional_criterion      = new Tracker_Report_AdditionalCriterion($key, $additional_criterion_value['value']);
                $additional_criteria[$key] = $additional_criterion;
            }
        } else {
            $additional_criteria_values = array();
            EventManager::instance()->processEvent(
                TRACKER_EVENT_REPORT_LOAD_ADDITIONAL_CRITERIA,
                array(
                    'additional_criteria_values' => &$additional_criteria_values,
                    'report'                     => $this,
                )
            );
            foreach ($additional_criteria_values as $key => $additional_criterion_value) {
                $additional_criterion      = new Tracker_Report_AdditionalCriterion($key, $additional_criterion_value['value']);
                $additional_criteria[$key] = $additional_criterion;
                if (isset($this->report_session)) {
                    $this->report_session->storeAdditionalCriterion($additional_criterion);
                }
            }
        }

        return $additional_criteria;
    }

    private function fetchUpdateRendererForm(Tracker_Report_Renderer $renderer) {
        $hp = Codendi_HTMLPurifier::instance();

        $update_renderer  = '';
        $update_renderer .= '<form action="" method="POST">';
        $update_renderer .= '<input type="hidden" name="report" value="'. $this->id .'" />';
        $update_renderer .= '<input type="hidden" name="renderer" value="'. (int)$renderer->id .'" />';
        $update_renderer .= '
            <label class="radio">
                <input type="radio" name="func" value="rename-renderer" id="tracker_renderer_updater_rename" />
                '. $GLOBALS['Language']->getText('plugin_tracker_report','update') .'
            </label>
            <div class="tracker-renderer-details">
               <label for="tracker_renderer_updater_rename_name">'. $GLOBALS['Language']->getText('plugin_tracker_report','name') .'</label>
               <input type="text"
                      name="new_name"
                      id="tracker_renderer_updater_rename_name"
                      value="'.  $hp->purify($renderer->name, CODENDI_PURIFIER_CONVERT_HTML)  .'" /><br />
               <label for="tracker_renderer_updater_rename_description">'. $GLOBALS['Language']->getText('plugin_tracker_report','description') .'</label>
               <textarea
                      name="new_description"
                      rows="5"
                      cols="30"
                      id="tracker_renderer_updater_rename_description"
                      >'.  $hp->purify($renderer->description, CODENDI_PURIFIER_CONVERT_HTML)  .'</textarea>
            </div>
        ';
        $update_renderer .= '<label class="radio"><input type="radio" name="func" value="delete-renderer" id="tracker_renderer_updater_delete" />'. $GLOBALS['Language']->getText('plugin_tracker_report', 'delete') .'</label>';
        $update_renderer .= '<br/>';
        $update_renderer .= '<input type="submit" class="btn btn-primary" value="'.  $hp->purify($GLOBALS['Language']->getText('global', 'btn_submit'), CODENDI_PURIFIER_CONVERT_HTML)  .'" onclick="if ($(\'tracker_renderer_updater_delete\').checked) return confirm(\''. $GLOBALS['Language']->getText('plugin_tracker_report', 'confirm_delete_renderer') .'\');"/> ';
        $update_renderer .= '</form>';

        return $update_renderer;
    }

    private function fetchAddRendererForm($current_renderer) {
        $hp = Codendi_HTMLPurifier::instance();

        $add_renderer  = '';
        $add_renderer .= '<form action="" method="POST">';
        $add_renderer .= '<input type="hidden" name="report" value="'. $this->id .'" />';
        $add_renderer .= '<input type="hidden" name="renderer" value="'. (int)$current_renderer->id .'" />';
        $add_renderer .= '<input type="hidden" name="func" value="add-renderer" />';
        $rrf = Tracker_Report_RendererFactory::instance();
        $types = $rrf->getTypes();
        if (count($types) > 1) { //No need to ask for type if there is only one
            $type = '<select name="new_type" id="tracker_renderer_add_type">';
            foreach($types as $key => $label) {
                $type .= '<option value="'. $key .'">'.  $hp->purify($label, CODENDI_PURIFIER_CONVERT_HTML)  .'</option>';
            }
            $type .= '</select>';
        } else {
            list(,$type) = each($types);
        }
        $add_renderer .= '<p><strong>' . $GLOBALS['Language']->getText('plugin_tracker_report','add_new') . ' ' . $type .'</strong></p>';
        $add_renderer .= '<p>';
        $add_renderer .= '<label for="tracker_renderer_add_name">'. $GLOBALS['Language']->getText('plugin_tracker_report','name') .'</label>
                         <input type="text" name="new_name" value="" id="tracker_renderer_add_name" />';

        $add_renderer .= '<label for="tracker_renderer_add_description">'. $GLOBALS['Language']->getText('plugin_tracker_report','description') .'</label>
                         <textarea
                            name="new_description"
                            id="tracker_renderer_add_description"
                            rows="5"
                            cols="30"></textarea>';

        $add_renderer .= '</p>';
        $add_renderer .= '<input type="submit" class="btn btn-primary" value="'.  $hp->purify($GLOBALS['Language']->getText('global', 'btn_submit'), CODENDI_PURIFIER_CONVERT_HTML)  .'" onclick="if (!$(\'tracker_renderer_add_name\').getValue()) { alert(\''. $GLOBALS['Language']->getText('plugin_tracker_report','name_mandatory') .'\'); return false;}"/> ';
        $add_renderer .= '</form>';

        return $add_renderer;
    }
}
