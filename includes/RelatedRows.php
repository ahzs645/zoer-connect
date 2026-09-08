<?php
namespace ZoerConnect;
/** Filter WordPress-owned dependent rows together; custom plugin tables need explicit policies. */
final class RelatedRows {
    public static function select(array $data,array $options): array {
        foreach(['posts','postmeta','comments','commentmeta','term_relationships','term_taxonomy','terms','termmeta'] as $key)if(!isset($data[$key])||!is_array($data[$key]))throw new \InvalidArgumentException('Complete related-table inventory required.');
        $out=$data;
        $out['posts']=Selection::rows($data['posts'],'posts',$options);
        $ids=array_fill_keys(array_map(static fn($r)=>(string)$r['ID'],$out['posts']),true);
        foreach($out['posts'] as $post)if(!empty($post['post_parent'])&&!isset($ids[(string)$post['post_parent']]))throw new \RuntimeException('Selected post has an excluded parent. Review selection.');
        $out['postmeta']=array_values(array_filter($data['postmeta'],static fn($r)=>isset($ids[(string)$r['post_id']])));
        foreach($out['postmeta'] as $meta)if(($meta['meta_key']??'')==='_thumbnail_id'&&!empty($meta['meta_value'])&&!isset($ids[(string)$meta['meta_value']]))throw new \RuntimeException('Selected post references an excluded featured image.');
        $out['comments']=array_values(array_filter(Selection::rows($data['comments'],'comments',$options),static fn($r)=>isset($ids[(string)$r['comment_post_ID']])));
        $comments=array_fill_keys(array_map(static fn($r)=>(string)$r['comment_ID'],$out['comments']),true);
        foreach($out['comments'] as $comment)if(!empty($comment['comment_parent'])&&!isset($comments[(string)$comment['comment_parent']]))throw new \RuntimeException('Comment references an excluded parent.');
        $out['commentmeta']=array_values(array_filter($data['commentmeta'],static fn($r)=>isset($comments[(string)$r['comment_id']])));
        $out['term_relationships']=array_values(array_filter($data['term_relationships'],static fn($r)=>isset($ids[(string)$r['object_id']])));
        // Retain taxonomy definitions: hierarchical ancestors can exist without direct post relations.
        // WordPress must recount taxonomy usage after cutover; existing counts are not portable.
        return $out;
    }
}
