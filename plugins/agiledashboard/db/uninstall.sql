DROP TABLE IF EXISTS plugin_agiledashboard_planning;
DROP TABLE IF EXISTS plugin_agiledashboard_planning_backlog_tracker;
DROP TABLE IF EXISTS plugin_agiledashboard_semantic_initial_effort;
DROP TABLE IF EXISTS plugin_agiledashboard_criteria;

DELETE FROM service WHERE short_name='plugin_agiledashboard';
