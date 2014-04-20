<?php
/**
 * Copyright (c) Xerox Corporation, Codendi Team, 2001-2009. All rights reserved
 *
 * This file is a part of Codendi.
 *
 * Codendi is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Codendi is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Codendi. If not, see <http://www.gnu.org/licenses/>.
 */

require_once(dirname(__FILE__).'/../../constants.php');
require_once('common/reference/CrossReferenceFactory.class.php');
require_once('common/reference/CrossReferenceManager.class.php');
require_once('www/project/admin/permissions.php');
require_once('common/include/Recent_Element_Interface.class.php');
require_once 'common/project/UGroupLiteralizer.class.php';
require_once 'common/project/ProjectManager.class.php';
require_once 'common/templating/TemplateRendererFactory.class.php';

class Tracker_Artifact implements Recent_Element_Interface, Tracker_Dispatchable_Interface {
    const REST_ROUTE        = 'artifacts';
    const NO_PARENT         = -1;
    const PERMISSION_ACCESS = 'PLUGIN_TRACKER_ARTIFACT_ACCESS';
    const REFERENCE_NATURE  = 'plugin_tracker_artifact';

    public $id;
    public $tracker_id;
    public $use_artifact_permissions;
    protected $per_tracker_id;
    protected $submitted_by;
    protected $submitted_on;

    protected $changesets;

    /**
     * @var array of Tracker_Artifact
     */
    private $ancestors;

    /**
     * @var Tracker
     */
    private $tracker;

    /**
     * @var Tracker_FormElementFactory
     */
    private $formElementFactory;

    /**
     * @var Tracker_HierarchyFactory
     */
    private $hierarchy_factory;

    /**
     * @var String
     */
    private $title;

    /**
     * @var String
     */
    private $status;

    /** @var Tracker_ArtifactFactory */
    private $artifact_factory;

    /** @var Tracker_Artifact[] */
    private $siblings;

    /** @var Tracker_Artifact[] */
    private $siblings_without_permission_checking;

    /** @var Tracker_Artifact */
    private $parent_without_permission_checking;

    /** @var Boolean[] */
    private $can_view_cache = array();

    /**
     * Constructor
     *
     * @param int     $id                       The Id of the artifact
     * @param int     $tracker_id               The tracker Id the artifact belongs to
     * @param int     $submitted_by             The id of the user who's submitted the artifact
     * @param int     $submitted_on             The timestamp of artifact submission
     *
     * @param boolean $use_artifact_permissions True if this artifact uses permission, false otherwise
     */
    public function __construct($id, $tracker_id, $submitted_by, $submitted_on, $use_artifact_permissions) {
        $this->id                       = $id;
        $this->tracker_id               = $tracker_id;
        $this->submitted_by             = $submitted_by;
        $this->submitted_on             = $submitted_on;
        $this->use_artifact_permissions = $use_artifact_permissions;
        $this->per_tracker_id           = null;
    }

    /**
     * Obtain event manager instance
     *
     * @return EventManager
     */
    private function getEventManager() {
        return EventManager::instance();
    }

    /**
     * Return true if given given artifact refer to the same DB object (basically same id).
     *
     * @param Tracker_Artifact $artifact
     *
     * @return Boolean
     */
    public function equals(Tracker_Artifact $artifact = null) {
        return $artifact && $this->id == $artifact->getId();
    }

    /**
    * Set the value of use_artifact_permissions
    *
    * @param bool $use_artifact_permissions
    *
    * @return bool true if the artifact has individual permissions set
    */
    public function setUseArtifactPermissions($use_artifact_permissions) {
        $this->use_artifact_permissions = $use_artifact_permissions;
    }

    /**
     * useArtifactPermissions
     * @return bool true if the artifact has individual permissions set
     */
    public function useArtifactPermissions() {
        return $this->use_artifact_permissions;
    }

    /**
     * userCanView - determine if the user can view this artifact.
     *
     * @param PFUser $user if not specified, use the current user
     *
     * @return boolean user can view the artifact
     */
    public function userCanView(PFUser $user = null) {
        $um = $this->getUserManager();
        if (! $user) {
            $user = $um->getCurrentUser();
        }

        if (! isset($this->can_view_cache[$user->getId()])) {
            if ($this->getTracker()->userIsAdmin() || $user->isSuperUser()) {
                $this->setUserCanView($user, true);
            } else {
                $permission_checker = new Tracker_Permission_PermissionChecker($um);
                $this->setUserCanView($user, $permission_checker->userCanView($user, $this));
            }
        }
        return $this->can_view_cache[$user->getId()];
    }

    public function setUserCanView(PFUser $user, $can_view) {
        $this->can_view_cache[$user->getId()] = $can_view;
    }

    public function userCanUpdate(PFUser $user) {
        if ($user->isAnonymous()) {
            return false;
        }
        return true;
    }

    public function permission_db_authorized_ugroups( $permission_type ) {
        $result = array();
        $res    = permission_db_authorized_ugroups($permission_type, $this->getId());
        if ( db_numrows($res) > 0 ) {
            while ( $row = db_fetch_array($res) ) {
                $result[] = $row;
            }
            return $result;
        } else {
            return false;
        }
    }


    /**
     * This method returns the artifact mail rendering
     *
     * @param array  $recipient
     * @param string $format, the mail format text or html
     * @param bool   $ignore_perms, indicates if we ignore various permissions
     *
     * @return string
     */
    public function fetchMail($recipient, $format, $ignore_perms=false) {
        $output = '';
        switch($format) {
            case 'html':
                $content = $this->fetchMailFormElements($recipient, $format, $ignore_perms);
                if ($content) {
                    $output .=
                    '<table style="width:100%">
                        <tr>
                            <td colspan="3" align="left">
                                <h2>'.
                                    $GLOBALS['Language']->getText('plugin_tracker_artifact_changeset', 'header_html_snapshot').'
                                </h2>
                            </td>
                        </tr>
                    </table>';
                    $output .= $content;
                }
                $output .=
                '<table style="width:100%">'.
                        $this->fetchMailFollowUp($recipient, $format, $ignore_perms).
                '</table>';
                break;
            default:
                $output .= PHP_EOL;
                //fields formelements
                $output .= $this->fetchMailFormElements($recipient, $format, $ignore_perms);
                $output .= $this->fetchMailFollowUp($recipient, $format, $ignore_perms);
                break;
        }
        return $output;
    }

    /**
     * Returns the artifact field for mail rendering
     *
     * @param array  $recipient
     * @param string $format, the mail format text or html
     * @param bool   $ignore_perms, indicates if we ignore various permissions
     *
     * @return String
     */
    public function fetchMailFormElements($recipient, $format, $ignore_perms = false) {
        $output = '';
        $toplevel_form_elements = $this->getTracker()->getFormElements();
        $this->prepareElementsForDisplay($toplevel_form_elements);

        foreach ($toplevel_form_elements as $formElement) {
            $output .= $formElement->fetchMailArtifact($recipient, $this, $format, $ignore_perms);
            if ($format == 'text' && $output) {
                $output .= PHP_EOL;
            }
        }

        if ($format == 'html') {
            $output = '<table width="100%">'.$output.'</table>';
        }

        return $output;
    }

    /** @param Tracker_FormElement[] */
    private function prepareElementsForDisplay($toplevel_form_elements) {
        foreach ($toplevel_form_elements as $formElement) {
            $formElement->prepareForDisplay();
        }
    }

    /**
     * Returns the artifact followup for mail rendering
     *
     * @param array  $recipient
     * @param string $format, the mail format text or html
     * @param bool   $ignore_perms, indicates if we ignore various permissions
     *
     * @return String
     */
    public function fetchMailFollowUp($recipient, $format, $ignore_perms=false) {
        $uh = UserHelper::instance();
        $um = UserManager::instance();
        $cs = $this->getChangesets();
        $hp = Codendi_HTMLPurifier::instance();
        $output = '';

        if($format == 'html'){
            $output .=
            '<tr>
                <td colspan="3" align="left">
                    <h2>'.
                        $GLOBALS['Language']->getText('plugin_tracker_include_artifact','follow_ups').'
                    </h2>
                </td>
            </tr>';
        }

        foreach ( $cs as $changeset ) {
            $comment = $changeset->getComment();
            /* @var $comment Tracker_Artifact_Changeset_Comment */
            $changes = $changeset->diffToPrevious($format, $recipient, $ignore_perms);
            if (empty($comment) || $comment->hasEmptyBody()) {
                //do not display empty comment
                continue;
            }
            switch ($format) {
                case 'html':
                    $followup = $comment->fetchMailFollowUp($format);
                    $output .=  $followup;
                    break;
                case 'text':
                    $user = $um->getUserById($comment->submitted_by);
                    $output .= PHP_EOL;
                    $output .= '----------------------------- ';
                    $output .= PHP_EOL;
                    $output .= $GLOBALS['Language']->getText('plugin_tracker_artifact','mail_followup_date') . util_timestamp_to_userdateformat($comment->submitted_on);
                    $output .= "\t" . $GLOBALS['Language']->getText('plugin_tracker_artifact','mail_followup_by') . $uh->getDisplayNameFromUser($user);
                    $output .= PHP_EOL;
                    $output .= $comment->getPurifiedBodyForText();
                    $output .= PHP_EOL;
                    $output .= PHP_EOL;
                    break;
                default:
                    $output .= '<!-- TODO -->';
                    break;
            }
        }
        return $output;
    }
    /**
     * Fetch the tooltip displayed on an artifact reference
     *
     * @param PFUser $user The user who fetch the tooltip
     *
     * @return string html
     */
    public function fetchTooltip($user) {
        $tooltip = $this->getTracker()->getTooltip();
        $html = '';
        if ($this->userCanView($user)) {
            $fields = $tooltip->getFields();
            if (!empty($fields)) {
                $html .= '<table>';
                foreach ($fields as $f) {
                    //TODO: check field permissions
                    $html .= $f->fetchTooltip($this);
                }
                $html .= '</table>';
            }
        }
        return $html;
    }

    /**
     * Fetch the artifact for the MyArtifact widget
     *
     * @param string $item_name The short name of the tracker this artifact belongs to
     * @param string $title     The title of this artifact
     *
     * @return string html
     */
    public function fetchWidget($item_name, $title) {
        $hp = Codendi_HTMLPurifier::instance();
        $html = '';
        $html .= ' <a class="direct-link-to-artifact" href="'.TRACKER_BASE_URL.'/?aid='. $this->id .'">';
        $html .= $hp->purify($item_name, CODENDI_PURIFIER_CONVERT_HTML);
        $html .= ' #';
        $html .= $this->id;
        if ($title) {
            $html .= ' - ';
            $html .= $hp->purify($title, CODENDI_PURIFIER_CONVERT_HTML);
        }

        $html .= '</a>';
        return $html;
    }

    /**
     * Returns HTML code to display the artifact title
     *
     * @param string $prefix The prefix to display before the title of the artifact. Default is blank.
     *
     * @return string The HTML code for artifact title
     */
    public function fetchTitle($prefix = '') {
        $html = '';
        $html .= $this->fetchHiddenTrackerId();
        $html .= '<div class="tracker_artifact_title">';
        $html .= $prefix;
        $html .= $this->getXRefAndTitle();
        $html .= '</div>';
        return $html;
    }

    public function fetchHiddenTrackerId() {
        return '<input type="hidden" id="tracker_id" name="tracker_id" value="'.$this->getTrackerId().'"/>';
    }

    public function getXRefAndTitle() {
        $hp = Codendi_HTMLPurifier::instance();
        return '<span class="'. $this->getTracker()->getColor() .' xref-in-title">' .
                $this->getXRef() .
                '<span> - </span>'.
                '</span>'.
                $hp->purify($this->getTitle());
    }
    /**
     * Get the artifact title, or null if no title defined in semantics
     *
     * @return string the title of the artifact, or null if no title defined in semantics
     */
    public function getTitle() {
        if ( ! isset($this->title)) {
            $this->title = null;
            if ($title_field = Tracker_Semantic_Title::load($this->getTracker())->getField()) {
                if ($title_field->userCanRead()) {
                    if ($last_changeset = $this->getLastChangeset()) {
                        if ($title_field_value = $last_changeset->getValue($title_field)) {
                            $this->title = $title_field_value->getText();
                        }
                    }
                }
            }
        }
        return $this->title;
    }

    /**
     * @param string $title
     */
    public function setTitle($title) {
        $this->title = $title;
    }

    /**
     * Get the artifact status, or null if no status defined in semantics
     *
     * @return string the status of the artifact, or null if no status defined in semantics
     */
    public function getStatus() {
        if ( ! isset($this->status)) {
            if ($status_field = Tracker_Semantic_Status::load($this->getTracker())->getField()) {
                if ($status_field->userCanRead()) {
                    $last_changeset = $this->getLastChangeset();
                    if ($last_changeset) {
                        $this->status = $status_field->getFirstValueFor($last_changeset);
                    }
                }
            }
        }
        return $this->status;
    }


    /**
     * @param String $status
     */
    public function setStatus($status) {
        $this->status = $status;
    }

    public function isOpen() {
        return Tracker_Semantic_Status::load($this->getTracker())->isOpen($this);
    }

    /**
     *
     * @param <type> $recipient
     * @param <type> $ignore_perms
     * @return <type>
     */
    public function fetchMailTitle($recipient, $format = 'text', $ignore_perms = false) {
        $output = '';
        if ( $title_field = Tracker_Semantic_Title::load($this->getTracker())->getField() ) {
            if ( $ignore_perms || $title_field->userCanRead($recipient) ) {
                if ($value = $this->getLastChangeset()->getValue($title_field)) {
                    if ($title = $value->getText() ) {
                        $output .= $title;
                    }
                }
            }
        }
        return $output;
    }

    /**
     * Returns HTML code to display the artifact history
     *
     * @return string The HTML code for artifact history
     */
    protected function fetchHistory() {
        $html = '';
        $html .= '<h4 class="tracker_artifact_tab">History</h4>';
        $h = new Tracker_History($this);
        $html .= $h->fetch();
        return $html;
    }

    /**
     * Returns HTML code to display the artifact history
     *
     * @param Codendi_Request $request The data from the user
     *
     * @return String The valid followup comment format
     */
    public function validateCommentFormat($request, $comment_format_field_name) {
        $comment_format = $request->get($comment_format_field_name);
        return Tracker_Artifact_Changeset_Comment::checkCommentFormat($comment_format);
    }

    /**
     * Process the artifact functions
     *
     * @param Tracker_IDisplayTrackerLayout  $layout          Displays the page header and footer
     * @param Codendi_Request                $request         The data from the user
     * @param PFUser                           $current_user    The current user
     *
     * @return void
     */
    public function process(Tracker_IDisplayTrackerLayout $layout, $request, $current_user) {
        switch ($request->get('func')) {
            case 'get-children':
                $children = $this->getChildPresenterCollection($current_user);
                $GLOBALS['Response']->sendJSON($children);
                exit;
                break;
            case 'update-comment':
                if ((int)$request->get('changeset_id') && $request->exist('content')) {
                    if ($changeset = $this->getChangeset($request->get('changeset_id'))) {
                        $comment_format = $this->validateCommentFormat($request, 'comment_format');
                        $changeset->updateComment($request->get('content'), $current_user, $comment_format, $_SERVER['REQUEST_TIME']);
                        if ($request->isAjax()) {
                            //We assume that we can only change a comment from a followUp
                            echo $changeset->getComment()->fetchFollowUp();
                        }
                    }
                }
                break;
            case 'preview-attachment':
            case 'show-attachment':
                if ((int)$request->get('field') && (int)$request->get('attachment')) {
                    $ff = Tracker_FormElementFactory::instance();
                    //TODO: check that the user can read the field
                    if ($field = $ff->getFormElementByid($request->get('field'))) {
                        $method = explode('-', $request->get('func'));
                        $method = $method[0];
                        $method .= 'Attachment';
                        if (method_exists($field, $method)) {
                            $field->$method($request->get('attachment'));
                        }
                    }
                }
                break;
            case 'artifact-delete-changeset':
                // @see comment in Tracker_Artifact_Changeset::fetchFollowUp()
                //if ($changeset = $this->getChangeset($request->get('changeset'))) {
                //    $changeset->delete($current_user);
                //}
                $GLOBALS['Response']->redirect('?aid='. $this->id);
                break;
            case 'artifact-update':
                $action = new Tracker_Action_UpdateArtifact(
                    $this,
                    $this->getFormElementFactory(),
                    $this->getEventManager()
                );
                $action->process($layout, $request, $current_user);
                break;
            case 'unassociate-artifact-to':
                $artlink_fields     = $this->getFormElementFactory()->getUsedArtifactLinkFields($this->getTracker());
                $linked_artifact_id = $request->get('linked-artifact-id');
                if (count($artlink_fields)) {
                    $this->unlinkArtifact($artlink_fields, $linked_artifact_id, $current_user);
                    $this->summonArtifactAssociators($request, $current_user, $linked_artifact_id);
                } else {
                    $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker', 'must_have_artifact_link_field'));
                    $GLOBALS['Response']->sendStatusCode(400);
                }
                break;
            case 'associate-artifact-to':
                $linked_artifact_id = $request->get('linked-artifact-id');
                if (!$this->linkArtifact($linked_artifact_id, $current_user)) {
                    $GLOBALS['Response']->sendStatusCode(400);
                } else {
                    $this->summonArtifactAssociators($request, $current_user, $linked_artifact_id);
                }
                break;
            case 'higher-priority-than':
                $dao = new Tracker_Artifact_PriorityDao();
                $dao->moveArtifactBefore($this->getId(), (int)$request->get('target-id'));
                break;
            case 'lesser-priority-than':
                $dao = new Tracker_Artifact_PriorityDao();
                $dao->moveArtifactAfter($this->getId(), (int)$request->get('target-id'));
                break;
            case 'show-in-overlay':
                $renderer = new Tracker_Artifact_EditOverlayRenderer($this, $this->getEventManager());
                $renderer->display($request, $current_user);
                break;
            case 'get-new-changesets':
                $changeset_id = $request->getValidated('changeset_id', 'uint', 0);
                $changeset_factory = $this->getChangesetFactory();
                $GLOBALS['Response']->sendJSON($changeset_factory->getNewChangesetsFormattedForJson($this, $changeset_id));
                break;
            case 'edit':
                $GLOBALS['Response']->redirect($this->getUri());
                break;
            case 'get-edit-in-place':
                $renderer = new Tracker_Artifact_Renderer_EditInPlaceRenderer($this, $this->getMustacheRenderer());
                $renderer->display($current_user, $this->getMustacheRenderer());
                break;
            case 'update-in-place':
                $renderer = new Tracker_Artifact_Renderer_EditInPlaceRenderer($this, $this->getMustacheRenderer());
                $renderer->updateArtifact($request, $current_user);
                break;
            default:
                if ($request->isAjax()) {
                    echo $this->fetchTooltip($current_user);
                } else {
                    $renderer = new Tracker_Artifact_ReadOnlyRenderer($this->getEventManager(), $this, $this->getFormElementFactory(), $layout);
                    $renderer->display($request, $current_user);
                }
                break;
        }
    }

    /** @return Tracker_Artifact[] */
    public function getChildrenForUser(PFUser $current_user) {
        $children = array();
        foreach ($this->getArtifactFactory()->getChildren($this) as $child) {
            if ($child->userCanView($current_user)) {
                $children[] = $child;
            }
        }
        return $children;
    }

    /** @return Tracker_ArtifactChildPresenter[] */
    private function getChildPresenterCollection(PFUser $current_user) {
        $presenters = array();
        foreach ($this->getChildrenForUser($current_user) as $child) {
            $tracker      = $child->getTracker();
            $semantics    = Tracker_Semantic_Status::load($tracker);
            $has_children = $child->hasChildren();

            $presenters[] = new Tracker_ArtifactChildPresenter($child, $this, $semantics);
        }
        return $presenters;
    }

    public function hasChildren() {
        return count($this->getArtifactFactory()->getChildren($this)) > 0;
    }

    /**
     * @see Tracker_CardPresenter::getAccentColor()
     *
     * @return string
     */
    public function getCardAccentColor(PFUser $current_user) {
        $selectbox = $this->getFormElementFactory()->getSelectboxFieldByNameForUser(
            $this->getTrackerId(),
            Tracker::TYPE_FIELD_NAME,
            $current_user
        );
        if (! $selectbox) {
            return '';
        }

        return $selectbox->getCurrentDecoratorColor($this);
    }

    /**
     * @return string html
     */
    public function fetchDirectLinkToArtifact() {
        return '<a class="direct-link-to-artifact" href="'. $this->getUri() . '">' . $this->getXRef() . '</a>';
    }

    /**
     * @return string html
     */
    public function fetchDirectLinkToArtifactWithTitle() {
        return '<a class="direct-link-to-artifact" href="'. $this->getUri() . '">' . $this->getXRefAndTitle() . '</a>';
    }

    /**
     * @return string html
     */
    public function fetchDirectLinkToArtifactWithoutXRef() {
        $hp = Codendi_HTMLPurifier::instance();
        return '<a class="direct-link-to-artifact" href="'. $this->getUri() . '">' . $hp->purify($this->getTitle()) . '</a>';
    }

    public function getRestUri() {
        return self::REST_ROUTE . '/' . $this->getId();
    }

    /**
     * @return string
     */
    public function getUri() {
        return TRACKER_BASE_URL .'/?aid=' . $this->getId();
    }

    /**
     * @return string the cross reference text: bug #42
     */
    public function getXRef() {
        return $this->getTracker()->getItemName() . ' #' . $this->getId();
    }

    /**
     * Fetch the html xref link to the artifact
     *
     * @return string html
     */
    public function fetchXRefLink() {
        return '<a class="cross-reference" href="/goto?'. http_build_query(array(
            'key'      => $this->getTracker()->getItemName(),
            'val'      => $this->getId(),
            'group_id' => $this->getTracker()->getGroupId(),
        )) .'">'. $this->getXRef() .'</a>';
    }

    /**
     * Return the URL to use when you want to create a new artifact of $target_tracker type linked to current artifact
     *
     * @param Tracker $target_tracker
     * @return String
     */
    public function getSubmitNewArtifactLinkedToMeUri(Tracker $target_tracker) {
        return TRACKER_BASE_URL . '/?'.http_build_query(array(
            'tracker'   => $target_tracker->getId(),
            'func'      => 'new-artifact-link',
            'id'        => $this->getId(),
            'immediate' => 1,
        ));
    }

    /**
     * Returns a Tracker_FormElementFactory instance
     *
     * @return Tracker_FormElementFactory
     */
    protected function getFormElementFactory() {
        if (empty($this->formElementFactory)) {
            $this->formElementFactory = Tracker_FormElementFactory::instance();
        }
        return $this->formElementFactory;
    }

    public function setFormElementFactory(Tracker_FormElementFactory $factory) {
        $this->formElementFactory = $factory;
    }

    /**
     * Returns a Tracker_ArtifactFactory instance
     *
     * @return Tracker_ArtifactFactory
     */
    protected function getArtifactFactory() {
        if ($this->artifact_factory) {
            return $this->artifact_factory;
        }
        return Tracker_ArtifactFactory::instance();
    }

    public function setArtifactFactory(Tracker_ArtifactFactory $artifact_factory) {
        $this->artifact_factory = $artifact_factory;
    }

    /**
     * Create the initial changeset of this artifact
     *
     * @param array   $fields_data The artifact fields values
     * @param PFUser  $submitter   The user who did the artifact submission
     * @param integer $submitted_on When the changeset is created
     *
     * @return int The Id of the initial changeset, or null if fields were not valid
     */
    public function createInitialChangeset($fields_data, $submitter, $submitted_on) {
        $creator = new Tracker_Artifact_Changeset_InitialChangesetCreator(
            new Tracker_Artifact_Changeset_InitialChangesetFieldsValidator($this->getFormElementFactory()),
            $this->getFormElementFactory(),
            $this->getChangesetDao(),
            $this->getArtifactFactory()
        );

        return $creator->create($this, $fields_data, $submitter, $submitted_on);
    }

    public function getErrors() {
        $list_errors = array();
        $is_valid = true;
        $used_fields    = $this->getFormElementFactory()->getUsedFields($this->getTracker());
        foreach ($used_fields as $field) {
            if ($field->hasErrors()) {
                $list_errors[] = $field->getId();
            }
        }
        return $list_errors;
    }

    /**
     * Update an artifact (means create a new changeset)
     *
     * @param array   $fields_data       Artifact fields values
     * @param string  $comment           The comment (follow-up) associated with the artifact update
     * @param PFUser  $submitter         The user who is doing the update
     * @param boolean $send_notification true if a notification must be sent, false otherwise
     * @param string  $comment_format    The comment (follow-up) type ("text" | "html")
     *
     * @throws Tracker_Exception In the validation
     * @throws Tracker_NoChangeException In the validation
     * @return Tracker_Artifact_Changeset|Boolean The new changeset if update is done without error, false otherwise
     */
    public function createNewChangeset($fields_data, $comment, PFUser $submitter, $send_notification = true, $comment_format = Tracker_Artifact_Changeset_Comment::TEXT_COMMENT) {
        $submitted_on = $_SERVER['REQUEST_TIME'];

        $creator = new Tracker_Artifact_Changeset_NewChangesetCreator(
            new Tracker_Artifact_Changeset_NewChangesetFieldsValidator($this->getFormElementFactory()),
            $this->getFormElementFactory(),
            $this->getChangesetDao(),
            $this->getChangesetCommentDao(),
            $this->getArtifactFactory(),
            $this->getEventManager(),
            $this->getReferenceManager()
        );
        return $creator->create($this, $fields_data, $comment, $submitter, $submitted_on, $send_notification, $comment_format);
    }

    /**
     * @return ReferenceManager
     */
    public function getReferenceManager() {
        return ReferenceManager::instance();
    }

    /**
     * Returns the tracker Id this artifact belongs to
     *
     * @return int The tracker Id this artifact belongs to
     */
    public function getTrackerId() {
        return $this->tracker_id;
    }

    /**
     * Returns the tracker this artifact belongs to
     *
     * @return Tracker The tracker this artifact belongs to
     */
    public function getTracker() {
        if (!isset($this->tracker)) {
            $this->tracker = TrackerFactory::instance()->getTrackerByid($this->tracker_id);
        }
        return $this->tracker;
    }

    public function setTracker(Tracker $tracker) {
        $this->tracker = $tracker;
        $this->tracker_id = $tracker->getId();
    }

    /**
     * Returns the last modified date of the artifact
     *
     * @return Integer The timestamp (-1 if no date)
     */
    public function getLastUpdateDate() {
        $last_changeset = $this->getLastChangeset();
        if ($last_changeset) {
            return $last_changeset->getSubmittedOn();
        }
        return -1;
    }

    /**
     * Returns the latest changeset of this artifact
     *
     * @return Tracker_Artifact_Changeset The latest changeset of this artifact, or null if no latest changeset
     */
    public function getLastChangeset() {
        $changesets = $this->getChangesets();
        $last_changeset = end($changesets);
        if ($last_changeset) {
            return $last_changeset;
        }
        return null;
    }

    /**
     * Returns the first changeset of this artifact
     *
     * @return Tracker_Artifact_Changeset The first changeset of this artifact
     */
    public function getFirstChangeset() {
        $changesets = $this->getChangesets();
        reset($changesets);
        list(,$c) = each($changesets);
        return $c;
    }

    /**
     * say if the changeset is the first one for this artifact
     *
     * @return bool
     */
    public function isFirstChangeset(Tracker_Artifact_Changeset $changeset) {
        $c = $this->getFirstChangeset();
        return $c->getId() == $changeset->getId();
    }

    /**
     * Returns all the changesets of this artifact
     *
     * @return array of Tracker_Artifact_Changeset The changesets of this artifact
     */
    public function getChangesets() {
        if ($this->changesets === null) {
            $this->changesets = $this->getChangesetFactory()->getChangesetsForArtifact($this);
        }
        return $this->changesets;
    }

    /**
     * @param array $changesets array of Tracker_Artifact_Changeset
     */
    public function setChangesets(array $changesets) {
        $this->changesets = $changesets;
    }

    public function clearChangesets() {
        $this->changesets = null;
    }

    public function addChangeset(Tracker_Artifact_Changeset $changeset) {
        $this->changesets[$changeset->getId()] = $changeset;
    }

    /**
     * Get all commentators of this artifact
     *
     * @return array of strings (username or emails)
     */
    public function getCommentators() {
        $commentators = array();
        foreach ($this->getChangesets() as $c) {
            if ($submitted_by = $c->getSubmittedBy()) {
                if ($user = $this->getUserManager()->getUserById($submitted_by)) {
                    $commentators[] = $user->getUserName();
                }
            } else if ($email = $c->getEmail()) {
                $commentators[] = $email;
            }
        }
        return $commentators;
    }

    /**
     * Return the ChangesetDao
     *
     * @return Tracker_Artifact_ChangesetDao The Dao
     */
    protected function getChangesetDao() {
        return new Tracker_Artifact_ChangesetDao();
    }

    /**
     * @return Tracker_Artifact_ChangesetFactory
     */
    protected function getChangesetFactory() {
        return new Tracker_Artifact_ChangesetFactory(
            $this->getChangesetDao(),
            new Tracker_Artifact_ChangesetJsonFormatter(
                $this->getMustacheRenderer()
            )
        );
    }

    /**
     * @return MustacheRenderer
     */
    private function getMustacheRenderer() {
        return TemplateRendererFactory::build()->getRenderer(dirname(TRACKER_BASE_DIR).'/templates') ;
    }

    /**
     * Return the ChangesetCommentDao
     *
     * @return Tracker_Artifact_Changeset_CommentDao The Dao
     */
    protected function getChangesetCommentDao() {
        return new Tracker_Artifact_Changeset_CommentDao();
    }

    /**
     * Returns the changeset of this artifact with Id $changeset_id, or null if not found
     *
     * @param int $changeset_id The Id of the changeset to retrieve
     *
     * @return Tracker_Artifact_Changeset The changeset, or null if not found
     */
    public function getChangeset($changeset_id) {
        if (! isset($this->changesets[$changeset_id])) {
            $this->changesets[$changeset_id] = $this->getChangesetFactory()->getChangeset($this, $changeset_id);
        }
        return $this->changesets[$changeset_id];
    }

    /**
     * Returns the previous changeset just before the changeset $changeset_id, or null if $changeset_id is the first one
     *
     * @param int $changeset_id The changeset reference
     *
     * @return Tracker_Artifact_Changeset The previous changeset, or null if not found
     */
    public function getPreviousChangeset($changeset_id) {
        $previous = null;
        $changesets = $this->getChangesets();
        reset($changesets);
        while ((list(,$changeset) = each($changesets)) && $changeset->id != $changeset_id) {
            $previous = $changeset;
        }
        return $previous;
    }

    public function exportCommentsToSOAP() {
        $soap_comments = array();
        foreach ($this->getChangesets() as $changeset) {
            $changeset_comment = $changeset->exportCommentToSOAP();
            if ($changeset_comment) {
                $soap_comments[] = $changeset_comment;
            }
        }
        return $soap_comments;
    }

    public function exportHistoryToSOAP(PFUser $user) {
        $soap_comments = array();
        foreach ($this->getChangesets() as $changeset) {
            $soap_comments[] = $changeset->getSoapValue($user);
        }
        return $soap_comments;
    }

    /**
     * Get the Id of this artifact
     *
     * @return int The Id of this artifact
     */
    public function getId() {
        return $this->id;
    }

    /**
     * Set the Id of this artifact
     *
     * @param int $id the new id of the artifact
     *
     * @return Tracker_Artifact
     */
    public function setId($id) {
        $this->id = $id;
        return $this;
    }

    /**
     * Get the value for this field in the changeset
     *
     * @param Tracker_FormElement_Field  $field     The field
     * @param Tracker_Artifact_Changeset $changeset The changeset. if null given take the last changeset of the artifact
     *
     * @return Tracker_Artifact_ChangesetValue | null
     */
    function getValue(Tracker_FormElement_Field $field, Tracker_Artifact_Changeset $changeset = null) {
        if (!$changeset) {
            $changeset = $this->getLastChangeset();
        }
        if ($changeset) {
            return $changeset->getValue($field);
        }
        return null;
    }

    /**
     * Returns the date (timestamp) the artifact ha been created
     *
     * @return int the timestamp for the date this aetifact was created
     */
    function getSubmittedOn() {
        return $this->submitted_on;
    }

    /**
     * Returns the user who submitted the artifact
     *
     * @return int the user id
     */
    function getSubmittedBy() {
        return $this->submitted_by;
    }

	/**
     * Returns the id of the artifact in this tracker
     *
     * @return int the artifact id
     */
    public function getPerTrackerArtifactId() {
        if ($this->per_tracker_id == null) {
            $this->per_tracker_id = $this->getDao()->getPerTrackerArtifactId($this->id);
        }
        return $this->per_tracker_id;
    }

    /**
     * Return Workflow the artifact should respect
     *
     * @return Workflow
     */
    public function getWorkflow() {
        $workflow = $this->getTracker()->getWorkflow();
        $workflow->setArtifact($this);
        return $workflow;
    }

    /**
     * Get the UserManager instance
     *
     * @return UserManager
     */
    public function getUserManager() {
        return UserManager::instance();
    }

    /**
     * User want to link an artifact to the current one
     *
     * @param int  $linked_artifact_id The id of the artifact to link
     * @param PFUser $current_user       The user who made the link
     *
     * @return bool true if success false otherwise
     */
    public function linkArtifact($linked_artifact_id, PFUser $current_user) {
        $artlink_fields = $this->getFormElementFactory()->getUsedArtifactLinkFields($this->getTracker());
        if (count($artlink_fields)) {
            $comment       = '';
            $artlink_field = $artlink_fields[0];
            $fields_data   = array();
            $fields_data[$artlink_field->getId()]['new_values'] = $linked_artifact_id;

            try {
                $this->createNewChangeset($fields_data, $comment, $current_user);
                return true;
            } catch (Tracker_NoChangeException $e) {
                $GLOBALS['Response']->addFeedback('info', $e->getMessage(), CODENDI_PURIFIER_LIGHT);
                return false;
            } catch (Tracker_Exception $e) {
                $GLOBALS['Response']->addFeedback('error', $e->getMessage());
                return false;
            }
        } else {
            $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_tracker', 'must_have_artifact_link_field'));
        }
    }

    private function unlinkArtifact($artlink_fields, $linked_artifact_id, PFUser $current_user) {
        $comment       = '';
        $artlink_field = $artlink_fields[0];
        $fields_data   = array();
        $fields_data[$artlink_field->getId()]['new_values'] = '';
        $fields_data[$artlink_field->getId()]['removed_values'] = array($linked_artifact_id => 1);

        try {
            $this->createNewChangeset($fields_data, $comment, $current_user);
        } catch (Tracker_NoChangeException $e) {
            $GLOBALS['Response']->addFeedback('info', $e->getMessage(), CODENDI_PURIFIER_LIGHT);
        } catch (Tracker_Exception $e) {
            $GLOBALS['Response']->addFeedback('error', $e->getMessage());
        }
    }

    /**
     * Get artifacts linked to the current artifact
     *
     * @param PFUser $user The user who should see the artifacts
     *
     * @return Array of Tracker_Artifact
     */
    public function getLinkedArtifacts(PFUser $user) {
        $artifact_links      = array();
        $artifact_link_field = $this->getAnArtifactLinkField($user);
        if ($artifact_link_field) {
            $artifact_links = $artifact_link_field->getLinkedArtifacts($this->getLastChangeset(), $user);
        }
        return $artifact_links;
    }

    /**
     * Get artifacts linked to the current artifact and sub artifacts
     *
     * @param PFUser $user The user who should see the artifacts
     *
     * @return Array of Tracker_Artifact
     */
    public function getLinkedArtifactsOfHierarchy(PFUser $user) {
        $artifact_links = $this->getLinkedArtifacts($user);
        $allowed_trackers = $this->getAllowedChildrenTypes();
        foreach ($artifact_links as $artifact_link) {
            $tracker = $artifact_link->getTracker();
            if (in_array($tracker, $allowed_trackers)) {
                $sub_linked_artifacts = $artifact_link->getLinkedArtifactsOfHierarchy($user);
                $artifact_links       = array_merge($artifact_links, $sub_linked_artifacts);
            }
        }
        return $artifact_links;
    }

    /**
     * Get artifacts linked to the current artifact if they belongs to the hierarchy
     *
     * @param PFUser $user The user who should see the artifacts
     *
     * @return Array of Tracker_Artifact
     */
    public function getHierarchyLinkedArtifacts(PFUser $user) {
        $allowed_trackers = $this->getAllowedChildrenTypes();
        $artifact_links   = $this->getLinkedArtifacts($user);
        foreach ($artifact_links as $key => $artifact) {
            if ( ! in_array($artifact->getTracker(), $allowed_trackers)) {
                unset($artifact_links[$key]);
            }
        }
        return $artifact_links;
    }

    /**
     * @return Tracker[]
     */
    public function getAllowedChildrenTypes() {
        return $this->getHierarchyFactory()->getChildren($this->getTrackerId());
    }

    /**
     * @return Tracker[]
     */
    public function getAllowedChildrenTypesForUser(PFUser $user) {
        $allowed_children = array();
        foreach ($this->getAllowedChildrenTypes() as $tracker) {
            if ($tracker->userCanSubmitArtifact($user)) {
                $allowed_children[] = $tracker;
            }
        }
        return $allowed_children;
    }

    /**
     * Get artifacts linked to the current artifact if
     * they are not in children.
     *
     * @param PFUser $user The user who should see the artifacts
     *
     * @return Array of Tracker_Artifact
     */
    public function getUniqueLinkedArtifacts(PFUser $user) {
        $sub_artifacts = $this->getLinkedArtifacts($user);
        $grandchild_artifacts = array();
        foreach ($sub_artifacts as $artifact) {
            $grandchild_artifacts = array_merge($grandchild_artifacts, $artifact->getLinkedArtifactsOfHierarchy($user));
        }
        array_filter($grandchild_artifacts);
        return array_diff($sub_artifacts, $grandchild_artifacts);
    }

    public function __toString() {
        return __CLASS__." #$this->id";
    }

    /**
     * Returns all ancestors of current artifact (from direct parent to oldest ancestor)
     *
     * @param PFUser $user
     *
     * @return Tracker_Artifact[]
     */
    public function getAllAncestors(PFUser $user) {
        if (!isset($this->ancestors)) {
            $this->ancestors = $this->getHierarchyFactory()->getAllAncestors($user, $this);
        }
        return $this->ancestors;
    }

    public function setAllAncestors(array $ancestors) {
        $this->ancestors = $ancestors;
    }

    /**
     * Return the parent artifact of current artifact if any
     *
     * @param PFUser $user
     *
     * @return Tracker_Artifact
     */
    public function getParent(PFUser $user) {
        return $this->getHierarchyFactory()->getParentArtifact($user, $this);
    }

    /**
     * Get parent artifact regartheless if user can access it
     *
     * Note: even if there are several parents, only the first one is returned
     *
     * @return Tracker_Artifact|null
     */
    public function getParentWithoutPermissionChecking() {
        if ($this->parent_without_permission_checking !== self::NO_PARENT && ! isset($this->parent_without_permission_checking)) {
            $dar = $this->getDao()->getParents(array($this->getId()));
            if ($dar && count($dar) == 1) {
                $this->parent_without_permission_checking = $this->getArtifactFactory()->getInstanceFromRow($dar->current());
            } else {
                $this->parent_without_permission_checking = self::NO_PARENT;
            }
        }
        if ($this->parent_without_permission_checking === self::NO_PARENT) {
            return null;
        }
        return $this->parent_without_permission_checking;
    }

    public function setParentWithoutPermissionChecking($parent) {
        $this->parent_without_permission_checking = $parent;
    }

    /**
     * Get artifacts that share same parent that mine (sista & bro)
     *
     * @param PFUser $user
     *
     * @return Tracker_Artifact[]
     */
    public function getSiblings(PFUser $user) {
        if (! isset($this->siblings)) {
            $this->siblings = array();
            foreach ($this->getSiblingsWithoutPermissionChecking() as $artifact) {
                if ($artifact->userCanView($user)) {
                    $this->siblings[] = $artifact;
                }
            }
        }
        return $this->siblings;
    }

    public function setSiblings(array $artifacts) {
        $this->siblings = $artifacts;
    }

    /**
     * Get all sista & bro regartheless if user can access them
     *
     * @return Tracker_Artifact[]
     */
    public function getSiblingsWithoutPermissionChecking() {
        if (! isset($this->siblings_without_permission_checking)) {
            $this->siblings_without_permission_checking = $this->getDao()->getSiblings($this->getId())->instanciateWith(array($this->getArtifactFactory(), 'getInstanceFromRow'));
        }
        return $this->siblings_without_permission_checking;
    }

    public function setSiblingsWithoutPermissionChecking($siblings) {
        $this->siblings_without_permission_checking = $siblings;
    }

    /**
     * Returns the previously injected factory (e.g. in tests), or a new
     * instance (e.g. in production).
     *
     * @return Tracker_HierarchyFactory
     */
    public function getHierarchyFactory() {
        if ($this->hierarchy_factory == null) {
            $this->hierarchy_factory = Tracker_HierarchyFactory::instance();
        }
        return $this->hierarchy_factory;
    }


    public function setHierarchyFactory($hierarchy = null) {
        $this->hierarchy_factory = $hierarchy;
    }

    /**
     * Returns the ids of the children of the tracker.
     *
     * @return array of int
     */
    protected function getChildTrackersIds() {
        $children_trackers_ids = array();
        $children_hierarchy_tracker = $this->getHierarchyFactory()->getChildren($this->getTrackerId());
        foreach ($children_hierarchy_tracker as $tracker) {
            $children_trackers_ids[] = $tracker->getId();
        }
        return $children_trackers_ids;
    }

    /**
     * Return the first (and only one) ArtifactLink field (if any)
     *
     * @return Tracker_FormElement_Field_ArtifactLink
     */
    public function getAnArtifactLinkField(PFUser $user) {
        return $this->getFormElementFactory()->getAnArtifactLinkField($user, $this->getTracker());
    }

    /**
     * Return the first BurndownField (if any)
     *
     * @return Tracker_FormElement_Field_Burndown
     */
    public function getABurndownField(PFUser $user) {
        return $this->getFormElementFactory()->getABurndownField($user, $this->getTracker());
    }

    /**
     * Invoke those we don't speak of which may want to redirect to a
     * specific page after an update/creation of this artifact.
     * If the summoning is not strong enough (or there is no listener) then
     * nothing is done. Else the client is redirected and
     * the script will die in agony!
     *
     * @param Codendi_Request $request The request
     */
    public function summonArtifactRedirectors(Codendi_Request $request, Tracker_Artifact_Redirect $redirect) {
        $this->getEventManager()->processEvent(
            TRACKER_EVENT_REDIRECT_AFTER_ARTIFACT_CREATION_OR_UPDATE,
            array(
                'request'  => $request,
                'artifact' => $this,
                'redirect' => $redirect
            )
        );
    }

    private function summonArtifactAssociators(Codendi_Request $request, PFUser $current_user, $linked_artifact_id) {
        $this->getEventManager()->processEvent(
            TRACKER_EVENT_ARTIFACT_ASSOCIATION_EDITED,
            array(
                'artifact'             => $this,
                'linked-artifact-id'   => $linked_artifact_id,
                'request'              => $request,
                'user'                 => $current_user,
                'form_element_factory' => $this->getFormElementFactory(),
            )
        );
    }

    public function delete(PFUser $user) {
        $this->getDao()->startTransaction();
        foreach($this->getChangesets() as $changeset) {
            $changeset->delete($user);
        }
        $this->getPermissionsManager()->clearPermission(self::PERMISSION_ACCESS, $this->getId());
        $this->getCrossReferenceManager()->deleteEntity($this->getId(), self::REFERENCE_NATURE, $this->getTracker()->getGroupId());
        $this->getDao()->deleteArtifactLinkReference($this->getId());
        $this->getDao()->deletePriority($this->getId());
        $this->getDao()->delete($this->getId());
        $this->getDao()->commit();
    }

    /**
     * Return the authorised ugroups to see the artifact
     *
     * @return Array
     */
    private function getAuthorisedUgroups () {
        $ugroups = array();
        //Individual artifact permission
        if ($this->useArtifactPermissions()) {
            $rows = $this->permission_db_authorized_ugroups('PLUGIN_TRACKER_ARTIFACT_ACCESS');
            if ( $rows !== false ) {
                foreach ($rows as $row) {
                    $ugroups[] = $row['ugroup_id'];
                }
            }
        } else {
            $permissions = $this->getTracker()->getAuthorizedUgroupsByPermissionType();
            foreach ($permissions  as $permission => $ugroups) {
                switch($permission) {
                    case Tracker::PERMISSION_FULL:
                    case Tracker::PERMISSION_SUBMITTER:
                    case Tracker::PERMISSION_ASSIGNEE:
                    case Tracker::PERMISSION_SUBMITTER_ONLY:
                        foreach ($ugroups as $ugroup) {
                            $ugroups[] = $ugroup['ugroup_id'];
                        }
                    break;
                }
            }
        }
        return $ugroups;
    }

    /**
     * Returns ugroups of an artifact in a human readable format
     *
     * @return array
     */
    public function exportPermissions() {
        $project     = ProjectManager::instance()->getProject($this->getTracker()->getGroupId());
        $literalizer = new UGroupLiteralizer();
        $ugroupsId     = $this->getAuthorisedUgroups();
        return $literalizer->ugroupIdsToString($ugroupsId, $project);
    }

    protected function getDao() {
        return new Tracker_ArtifactDao();
    }

    protected function getPermissionsManager() {
        return PermissionsManager::instance();
    }

    protected function getCrossReferenceManager() {
        return new CrossReferenceManager();
    }

    protected function getCrossReferenceFactory() {
        return new CrossReferenceFactory($this->getId(), self::REFERENCE_NATURE, $this->getTracker()->getGroupId());
    }

    /**
     * Get the cross references from/to this artifact.
     *
     * Note: the direction of cross references is not returned
     *
     * @return array of references info to be sent in soap format: array('ref' => ..., 'url' => ...)
     */
    public function getCrossReferencesSOAPValues() {
         $soap_value = array();
         $cross_reference_factory = $this->getCrossReferenceFactory();
         $cross_reference_factory->fetchDatas();

         $cross_references = $cross_reference_factory->getFormattedCrossReferences();
         foreach ($cross_references as $array_of_references_by_direction) {
             foreach ($array_of_references_by_direction as $reference) {
                $soap_value[] = array(
                    'ref' => $reference['ref'],
                    'url' => $reference['url'],
                );
             }
         }
         return $soap_value;
    }

    public function getSoapValue(PFUser $user) {
        $soap_artifact = array();
        if ($this->userCanView($user)) {
            $last_changeset = $this->getLastChangeset();

            $soap_artifact['artifact_id']      = $this->getId();
            $soap_artifact['tracker_id']       = $this->getTrackerId();
            $soap_artifact['submitted_by']     = $this->getSubmittedBy();
            $soap_artifact['submitted_on']     = $this->getSubmittedOn();
            $soap_artifact['cross_references'] = $this->getCrossReferencesSOAPValues();
            $soap_artifact['last_update_date'] = $last_changeset->getSubmittedOn();

            $soap_artifact['value'] = array();
            foreach ($this->getFormElementFactory()->getUsedFieldsForSoap($this->getTracker()) as $field) {
                $value = $field->getSoapValue($user, $last_changeset);
                if ($value !== null) {
                    $soap_artifact['value'][] = $value;
                }
            }
        }
        return $soap_artifact;
    }
}
