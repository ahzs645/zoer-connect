<?php
namespace ZoerConnect;

/** A connector-only credential. The raw value is shown once and never persisted. */
final class ConnectionKey {
    public const OPTION = 'zoer_connect_connection';

    public static function create(int $owner): array {
        if($owner<1)throw new \InvalidArgumentException('Administrator required.');
        $secret='zc_'.bin2hex(random_bytes(32));
        return [$secret,['hash'=>hash('sha256',$secret),'owner'=>$owner,'createdAt'=>gmdate('c'),'push'=>false,'pull'=>false]];
    }

    public static function matches(string $secret, array $record): bool {
        return preg_match('/^zc_[a-f0-9]{64}$/D',$secret)===1
            && is_string($record['hash']??null)
            && hash_equals($record['hash'],hash('sha256',$secret));
    }

    public static function permits(array $record, string $scope): bool {
        return $scope==='status' || (in_array($scope,['push','pull'],true) && ($record[$scope]??false)===true);
    }
}
