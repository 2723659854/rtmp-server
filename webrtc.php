<?php

/**
 * @purpose WebRTC SDK 启动示例
 * @author yanglong
 * @command php webrtc.php
 */

use Xiaosongshu\Webrtc\WebRTCServer;

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__."/config/app.php";

$server = new WebRTCServer(WS_PORT, UDP_PORT, STUN_PORT, __DIR__ . "/webrtc_debug.log",__DIR__.'/webrtc');
// 设置公网ip，当对外提供服务的时候务必设置
$server->publicIp = PUBLIC_IP;
// 是否开发模式
$server->isDev = false;

$rooms = [];

$server->onOpen = function ($label, $clientId, WebRTCServer $srv) use (&$rooms) {
    $total = count($srv->getClientIds());
    $msg = "[onOpen] new clientId={$clientId} label={$label} 当前连接总数={$total}\n";
    echo $msg;
    $srv->_log_std($msg);
};

$server->onJoin = function (int $clientId, array $msg, WebRTCServer $srv, &$handled) use (&$rooms) {
    $role = (string)($msg['role'] ?? '');
    $streamId = (string)($msg['streamId'] ?? '');
    if ($streamId === '' || !in_array($role, ['push', 'play'], true)) {
        return;
    }
    if (!isset($rooms[$streamId])) {
        $rooms[$streamId] = [
            'pushId' => null,
            'subscribers' => [],
            'createdAt' => time(),
        ];
    }

    if ($role === 'push') {
        if ($rooms[$streamId]['pushId'] !== null && $rooms[$streamId]['pushId'] !== $clientId) {
            $oldId = $rooms[$streamId]['pushId'];
            $srv->_log_std("[onJoin] streamId={$streamId} 新推流端 {$clientId} 顶掉旧的 {$oldId}\n");
        }
        $rooms[$streamId]['pushId'] = $clientId;
        $msg = "[onJoin] client={$clientId} 作为推流端加入房间 streamId={$streamId}\n";
        echo $msg;
        $srv->_log_std($msg);
    } else {
        $rooms[$streamId]['subscribers'][$clientId] = true;
        $pushId = $rooms[$streamId]['pushId'];
        $viewerCnt = count($rooms[$streamId]['subscribers']);
        $msg = "[onJoin] client={$clientId} 作为观众加入房间 streamId={$streamId} 当前观众数={$viewerCnt} 推流端=" . ($pushId === null ? '无' : $pushId) . "\n";
        echo $msg;
        $srv->_log_std($msg);
    }

};

$server->onPublisher = function (int $clientId, array $ctx, WebRTCServer $srv) use (&$rooms) {
    $streamId = (string)($ctx['streamId'] ?? '');
    $localSsrc = $ctx['localSsrc'] ?? [];
    $videoPTs = array_keys($ctx['videoPTs'] ?? []);
    $audioPTs = array_keys($ctx['audioPTs'] ?? []);

    if ($streamId !== '') {
        if (!isset($rooms[$streamId])) {
            $rooms[$streamId] = [
                'pushId' => null,
                'subscribers' => [],
                'createdAt' => time(),
            ];
            $srv->_log_std("[onPublisher] WHIP推流端创建房间 streamId={$streamId} (onJoin未触发)\n");
        }
        $rooms[$streamId]['pushId'] = $clientId;
        $rooms[$streamId]['publisherReadyAt'] = time();
    }

    echo "[onPublisher] 推流端就绪 clientId={$clientId} streamId={$streamId} "
        . "videoSSRC=" . ($localSsrc['video'] ?? '?') . " audioSSRC=" . ($localSsrc['audio'] ?? '?')
        . " videoPTs=[" . implode(',', $videoPTs) . "] audioPTs=[" . implode(',', $audioPTs) . "]\r\n";
    $_msg = "[onPublisher] 推流端就绪 clientId={$clientId} streamId={$streamId} "
        . "videoSSRC=" . ($localSsrc['video'] ?? '?') . " audioSSRC=" . ($localSsrc['audio'] ?? '?')
        . " videoPTs=[" . implode(',', $videoPTs) . "] audioPTs=[" . implode(',', $audioPTs) . "]\n";
    $srv->_log_std($_msg);

    if ($streamId !== '' && isset($rooms[$streamId])) {
        $subIds = array_keys($rooms[$streamId]['subscribers'] ?? []);
        foreach ($subIds as $subId) {
            $subId = (int)$subId;
            $offer = $srv->makeSfuOfferForSubscriber($subId, $clientId);
            if ($offer === null) {
                $srv->_log_std("[onPublisher] subscriberId={$subId} makeSfuOfferForSubscriber(pub={$clientId}) FAIL\n");
                continue;
            }
            $ok = $srv->sendSignaling($subId, ['type' => 'offer', 'sdp' => $offer]);
            $srv->_log_std("[onPublisher] subscriberId={$subId} streamId={$streamId} <- SFU offer sent (pub={$clientId}, len=" . strlen($offer) . ", send=" . ($ok ? 'ok' : 'fail') . ")\n");
        }

        if (!empty($subIds)) {
            $srv->broadcastSignaling($subIds, [
                'type' => 'publisher-ready',
                'streamId' => $streamId,
                'videoPTs' => $videoPTs,
                'audioPTs' => $audioPTs,
            ]);
        }
    }
};

$server->onSubscriber = function (int $clientId, array $ctx, WebRTCServer $srv) use (&$rooms) {
    $streamId = (string)($ctx['streamId'] ?? '');
    $pushId = $ctx['pushClientId'] ?? null;

    $viewerCnt = isset($rooms[$streamId]['subscribers']) ? count($rooms[$streamId]['subscribers']) : 0;
    echo "[onSubscriber] 新订阅者 clientId={$clientId} streamId={$streamId} 推流端=" . ($pushId === null ? '等待中' : $pushId) . " 当前观众数={$viewerCnt}\r\n";
    $_msg = "[onSubscriber] 新订阅者 clientId={$clientId} streamId={$streamId} 推流端=" . ($pushId === null ? '等待中' : $pushId) . " 当前观众数={$viewerCnt}\n";
    $srv->_log_std($_msg);

    $srv->setClientMeta($clientId, 'subscriberHandled', 'true');

    $offerSent = false;
    if ($streamId !== '') {

        $currentPushId = $pushId;
        if ($currentPushId === null && isset($rooms[$streamId])) {
            $currentPushId = $rooms[$streamId]['pushId'] ?? null;
        }
        if ($currentPushId !== null && isset($srv->clients[$currentPushId])) {
            $offer = $srv->makeSfuOfferForSubscriber($clientId, (int)$currentPushId);
            if ($offer !== null) {
                $ok = $srv->sendSignaling($clientId, ['type' => 'offer', 'sdp' => $offer]);
                $srv->_log_std("[onSubscriber] subscriberId={$clientId} streamId={$streamId} <- SFU offer sent immediately (push={$currentPushId}, len=" . strlen($offer) . ", send=" . ($ok ? 'ok' : 'fail') . ")\n");
                $offerSent = true;
            } else {
                $srv->_log_std("[onSubscriber] subscriberId={$clientId} makeSfuOfferForSubscriber(push={$currentPushId}) FAIL, wait onPublisher to re-fire\n");
            }
        }
    }

    $kick1 = $srv->kickFaststartForSubscriber($clientId);
    $srv->_log_std("[onSubscriber] subscriberId={$clientId} kickFaststart(T+0 join) pliSent=" . ($kick1['pliSent'] ? 'yes' : 'no') . " gopBurst=" . (int)$kick1['gopBurst'] . " offerSent=" . ($offerSent ? 'yes' : 'no') . "\n");
};

$server->onOffer = function (int $clientId, string $offerSdp, string $answerSdp, WebRTCServer $srv) {
    $role = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
    $msg = "[onOffer] client={$clientId} role={$role} offer len=" . strlen($offerSdp) . " answer len=" . strlen($answerSdp) . "\n";
    echo $msg;
    $srv->_log_std($msg);
};

$server->onAnswer = function (int $clientId, string $sdp, WebRTCServer $srv, &$handled) {
    $role = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
    $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');
    $msg = "[onAnswer] client={$clientId} role={$role} streamId={$streamId} answer sdp len=" . strlen($sdp) . "\n";
    echo $msg;
    $srv->_log_std($msg);

    if ($role === 'play' && $streamId !== '') {
        $kick2 = $srv->kickFaststartForSubscriber($clientId);
        $srv->_log_std("[onAnswer] subscriberId={$clientId} kickFaststart(T+answer) pliSent=" . ($kick2['pliSent'] ? 'yes' : 'no') . " gopBurst=" . (int)$kick2['gopBurst'] . "\n");
    }
};

$server->onCandidate = function (int $clientId, array $msg, WebRTCServer $srv, &$handled) {
    $role = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
    $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');
    $cand = (string)($msg['candidate'] ?? '');
    $msg2 = "[onCandidate] client={$clientId} role={$role} streamId={$streamId} candidate len=" . strlen($cand) . "\n";
    echo $msg2;
    $srv->_log_std($msg2);
};

$server->onMediaConnected = function (int $clientId, array $rtp, WebRTCServer $srv) use (&$rooms) {
    $pt = (int)($rtp['pt'] ?? -1);
    $ssrc = (int)($rtp['ssrc'] ?? 0);
    $seq = (int)($rtp['seq'] ?? 0);
    $role = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
    $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');

    $videoPTs = $srv->clients[$clientId]['videoPTs'] ?? [];
    $audioPTs = $srv->clients[$clientId]['audioPTs'] ?? [];
    $kind = isset($videoPTs[$pt]) ? 'video' : (isset($audioPTs[$pt]) ? 'audio' : 'unknown');

    $msg = "[onMediaConnected] 媒体首帧 client={$clientId} role={$role} streamId={$streamId} "
        . "kind={$kind} pt={$pt} ssrc={$ssrc} seq={$seq}\n";
    echo $msg;
    $srv->_log_std($msg);
};

$server->onSignaling = function (int $clientId, array $msg, WebRTCServer $srv, &$handled) {
    $type = (string)($msg['type'] ?? '?');
    echo "[onSignaling] client={$clientId} type={$type}\r\n";
};

$server->onLeave = function (int $clientId, WebRTCServer $srv) use (&$rooms) {
    $role = (string)$srv->getClientMeta($clientId, 'role', '');
    $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');

    if ($streamId !== '' && isset($rooms[$streamId])) {
        if ($role === 'push') {
            if (($rooms[$streamId]['pushId'] ?? null) === $clientId) {
                $rooms[$streamId]['pushId'] = null;
                $msg = "[onLeave] 推流端 client={$clientId} 离开房间 streamId={$streamId} (无推流)\n";
                echo $msg;
                $srv->_log_std($msg);

                $subIds = array_keys($rooms[$streamId]['subscribers'] ?? []);
                if (!empty($subIds)) {
                    $srv->broadcastSignaling($subIds, ['type' => 'publisher-left', 'streamId' => $streamId]);
                }
            }
        } elseif ($role === 'play') {
            if (isset($rooms[$streamId]['subscribers'][$clientId])) {
                unset($rooms[$streamId]['subscribers'][$clientId]);
            }
            $viewerCnt = count($rooms[$streamId]['subscribers'] ?? []);
            $msg = "[onLeave] 观众 client={$clientId} 离开房间 streamId={$streamId} 当前观众数={$viewerCnt}\n";
            echo $msg;
            $srv->_log_std($msg);
        }

        if (($rooms[$streamId]['pushId'] ?? null) === null && empty($rooms[$streamId]['subscribers'])) {
            unset($rooms[$streamId]);
            $msg = "[onLeave] 空房间已回收 streamId={$streamId}\n";
            echo $msg;
            $srv->_log_std($msg);
        }
    } else {
        $msg = "[onLeave] 连接关闭 client={$clientId}\n";
        echo $msg;
        $srv->_log_std($msg);
    }
};

$server->onClose = function ($id, WebRTCServer $srv) {
    echo "[onClose] WebSocket closed client={$id}\r\n";
};

$server->onmessage = function (string $message, int $clientId, WebRTCServer $srv) use (&$rooms) {
    $trimMsg = trim($message);
    $role = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
    $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');
    $label = (string)$srv->getClientMeta($clientId, 'label', 'client#' . $clientId);

    $srv->_log_std("[onmessage] client={$clientId} label={$label} role={$role} streamId={$streamId} msg=\"{$trimMsg}\"\n");
    $reply = "服务器收到：\"{$trimMsg}\" （时间:" . date('H:i:s') . " | clientId={$clientId} | role={$role}）";
    $ok = $srv->sendDataChannel($clientId, $reply);
    $srv->_log_std("[onmessage] client={$clientId} send reply ok=" . ($ok ? 'YES' : 'NO') . " reply=\"{$reply}\"\n");

    if ($streamId !== '' && isset($rooms[$streamId])) {
        $targets = $srv->getClientsInStreamRoom($streamId, [$clientId]);
        if (!empty($targets)) {
            $chatMsg = ($role === 'push' ? '【主播】' : '【观众】')
                . "{$label}(id{$clientId}): {$trimMsg}";
            $sent = $srv->broadcastDataChannel($targets, $chatMsg);
            $srv->_log_std("[onmessage] 房间聊天 streamId={$streamId} targets=" . count($targets) . " sent={$sent} msg=\"{$chatMsg}\"\n");
        }
    } else {
        $targets = $srv->getClientsWithDataChannel([$clientId]);
        if (!empty($targets)) {
            $chatMsg = "【{$label}(id{$clientId})】: {$trimMsg}";
            $sent = $srv->broadcastDataChannel($targets, $chatMsg);
            $srv->_log_std("[onmessage] 全局聊天 targets=" . count($targets) . " sent={$sent} msg=\"{$chatMsg}\"\n");
        }
    }
};


$server->start();
