<?php
/**
 * Copyright Enalean (c) 2014. All rights reserved.
 *
 * Tuleap and Enalean names and logos are registrated trademarks owned by
 * Enalean SAS. All other trademarks or names are properties of their respective
 * owners.
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

require_once 'common/svn/SVN_Hooks.class.php';

/**
 * I'm responsible of handling what happens in pre-commit subversion hook
 */
class SVN_Hook_PreCommit extends SVN_Hook {

    /**
     * Check if the commit message is valid
     *
     * @param String $repository
     * @param String $commit_message
     *
     * @throws Exception
     */
    public function assertCommitMessageIsValid($repository, $commit_message) {
        if ($this->optionDoesNotAllowEmptyCommitMessage() && $commit_message === '') {
            throw new Exception('Commit message must not be empty');
        }

        $project = $this->getProjectFromRepositoryPath($repository);
        $this->message_validator->assertCommitMessageIsValid($project, $commit_message);
    }

    private function optionDoesNotAllowEmptyCommitMessage() {
        return ! Config::get('sys_allow_empty_svn_commit_message');
    }
}