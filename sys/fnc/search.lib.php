<?php
/** Always recheck source visibility; the index and its cache may be older. */
function atm_search_result_visible($result, $indexing = false)
{
    $registry = Register::getInstance();
    $db = $registry['DB'];
    $acl = isset($registry['ACL']) ? $registry['ACL'] : null;
    $table = isset($result['entity_table']) ? $result['entity_table'] : '';
    if (preg_match('/^posts:([0-9]+)$/', $table, $match)) { $table = 'posts'; $result['source_id'] = (int)$match[1]; }
    $id = isset($result['entity_id']) ? (int)$result['entity_id'] : 0;
    if ($id < 1 || !in_array($table, array('news','stat','loads','shop_products','forums','forum_cat','themes','posts'), true)) return false;
    try {
        $name = $db->getFullTableName($table);
        // Post index entries point to the topic, not to the individual post.
        if ($table === 'posts') {
            if (empty($result['source_id'])) return false; // Legacy entries cannot prove the post still exists.
            $posts = $db->getFullTableName('posts');
            $post = $db->query("SELECT `id_theme` FROM `{$posts}` WHERE `id` = " . (int)$result['source_id'] . " LIMIT 1");
            if (empty($post[0]) || (int)$post[0]['id_theme'] !== $id) return false;
            $name = $db->getFullTableName('themes'); $table = 'themes';
        }
        $rows = $db->query("SELECT * FROM `{$name}` WHERE `id` = {$id} LIMIT 1");
        if (empty($rows[0])) return false;
        $source = $rows[0];
        if (in_array($table, array('news','stat','loads'), true)) {
            if (empty($source['available']) || $source['premoder'] !== 'confirmed') return false;
            if ($indexing) return true;
            if (!$acl || !$acl->turn(array($table, 'view_materials'), false) || !Config::read('active', $table)) return false;
            $ids = array((int)$source['category_id']);
            if (in_array($table, array('news','stat'), true) && function_exists('atm_use_multicategories') && atm_use_multicategories($table)) {
                atm_ensure_categories_bind_table();
                $bind = $db->getFullTableName('categories_bind');
                foreach ($db->query("SELECT `category_id` FROM `{$bind}` WHERE `entity_id` = {$id} AND `module` = '{$table}'") as $row) $ids[] = (int)$row['category_id'];
            }
            $categories = $db->getFullTableName($table . '_categories');
            foreach ($db->query("SELECT `no_access` FROM `{$categories}` WHERE `id` IN (" . implode(',', $ids) . ")") as $category) {
                if (ACL::checkCategoryAccess((string)$category['no_access'])) return true;
            }
            return false;
        }
        if ($table === 'shop_products') return !empty($source['active']) && ($indexing || (Config::read('active','shop') && $acl && $acl->turn(array('shop','view_catalog'), false)));
        if ($indexing) return true;
        if (!$acl || !Config::read('active','forum')) return false;
        if ($table === 'forum_cat') return $acl->turn(array('forum','view_forums_list'), false);
        if ($table === 'themes') {
            $group = isset($_SESSION['user']['status']) ? (int)$_SESSION['user']['status'] : 0;
            $denied = array_filter(explode('.', (string)$source['group_access']), 'strlen');
            if (in_array($group, array_map('intval', $denied), true)) return false;
            $forumId = (int)$source['id_forum'];
            if (!$acl->turn(array('forum','view_themes',$forumId), false)) return false;
        } else {
            $forumId = $id;
        }
        $seen = array();
        while ($forumId > 0) {
            if (isset($seen[$forumId])) return false;
            $seen[$forumId] = true;
            if (!$acl->turn(array('forum','view_forums',$forumId), false)) return false;
            $forums = $db->getFullTableName('forums');
            $rows = $db->query("SELECT * FROM `{$forums}` WHERE `id` = {$forumId} LIMIT 1");
            if (empty($rows[0])) return false;
            $forum = $rows[0];
            $enoughPosts = !empty($forum['lock_posts']) && isset($_SESSION['user']['posts']) && (int)$_SESSION['user']['posts'] >= (int)$forum['lock_posts'];
            if (!empty($forum['lock_passwd']) && empty($_SESSION['access_forum_' . $forumId]) && !$enoughPosts) return false;
            if (empty($forum['lock_passwd']) && !empty($forum['lock_posts']) && !$enoughPosts) return false;
            $forumId = (int)$forum['parent_forum_id'];
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
