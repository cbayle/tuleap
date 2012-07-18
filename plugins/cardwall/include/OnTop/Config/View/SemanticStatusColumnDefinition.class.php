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

require_once 'ColumnDefinition.class.php';

class Cardwall_OnTop_Config_View_SemanticStatusColumnDefinition extends Cardwall_OnTop_Config_View_ColumnDefinition {

    protected function fetchSpeech() {
        $field    = $this->config->getTracker()->getStatusField();
        return $this->translate('plugin_cardwall', 'on_top_semantic_status_column_definition_speech', array($this->purify($field->getLabel())));
    }

    protected function fetchColumnHeader(Cardwall_Column $column) {
        return $this->purify($column->label);
    }

    protected function fetchAdditionalColumnHeader() {
        return '';
    }
}
?>