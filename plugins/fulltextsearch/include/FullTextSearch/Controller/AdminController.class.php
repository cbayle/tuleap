<?php
/**
 * Copyright (c) Enalean, 2012. All Rights Reserved.
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
 * Controller for site admin views
 */
class FullTextSearch_Controller_Admin  extends FullTextSearch_Controller_Search {

    /* FullTextSearch_DocmanSystemEventManager */
    private $system_event_manager;

    public function __construct(Codendi_Request $request, FullTextSearch_ISearchDocumentsForAdmin $client, FullTextSearch_DocmanSystemEventManager $system_event_manager) {
        parent::__construct($request, $client);

        $this->system_event_manager = $system_event_manager;
    }

    public function getIndexStatus() {
        return $this->client->getStatus();
    }

    public function index() {
        $project_manager    = ProjectManager::instance();
        $project_presenters = $this->getProjectPresenters($project_manager->getProjectsByStatus(Project::STATUS_ACTIVE));

        $GLOBALS['HTML']->header(array('title' => $GLOBALS['Language']->getText('plugin_fulltextsearch', 'admin_title')));
        $this->renderer->renderToPage('admin', new FullTextSearch_Presenter_AdminPresenter($project_presenters));
        $GLOBALS['HTML']->footer(array());
    }

    public function reindex($group_id) {
        $project = $this->request->getProject();

        $this->system_event_manager->queueNewProjectReindexation($group_id);

        $this->addFeedback('info', $GLOBALS['Language']->getText('plugin_fulltextsearch', 'waiting_for_reindexation', array(util_unconvert_htmlspecialchars($project->getPublicName()))));
        $this->index();
    }

    private function getProjectPresenters($projects) {
        $presenters = array();
        foreach ($projects as $project) {
            $presenters[] = new FullTextSearch_Presenter_ProjectPresenter($project);
        }

        return $presenters;
    }
}