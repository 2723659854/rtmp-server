<?php

namespace Root;

use Xiaosongshu\Flv2mp4\Flv\WebRtcFlvRelay;
use Xiaosongshu\Flv2mp4\Opus\OpusWorkerClient;
use Xiaosongshu\Webrtc\WebRTCServer;

/**
 * @purpose webrtc直播服务代理
 * @author yanglong
 * @time 2026年8月13日15:24:20
 */
class WebrtcGateway
{

    private WebRTCServer $server;
    private array $config;
    private array $rooms = [];
    private array $flvRelays = [];
    private string $wsFlvPushUrl;
    private int $opusWorkerPort;
    private bool $shuttingDown = false;

    private bool $webrtc2rtmp = false;

    public function __construct(WebRTCServer $server, array $config = [])
    {
        $this->server = $server;
        $this->config = $config;
        $this->wsFlvPushUrl = (string)($this->config['wsFlvPushUrl'] ?? 'ws://127.0.0.1:8501/live/{streamId}');
        $this->webrtc2rtmp = (bool)($this->config['webrtc2rtmp'] ?? false);
        $this->opusWorkerPort = (int)($this->config['opusWorkerPort'] ?: 8330);
        $this->server->onOpen = [$this,'onOpen'];
        $this->server->onJoin = [$this,'onJoin'];
        $this->server->onPublisher = [$this,'onPublisher'];
        $this->server->onSubscriber = [$this,'onSubscriber'];
        $this->server->onOffer = [$this,'onOffer'];
        $this->server->onAnswer = [$this,'onAnswer'];
        $this->server->onCandidate = [$this,'onCandidate'];
        $this->server->onMediaConnected = [$this,'onMediaConnected'];
        $this->server->onSignaling = [$this,'onSignaling'];
        $this->server->onRtp = [$this,'onRtp'];
        $this->server->onLeave = [$this,'onLeave'];
        $this->server->onClose = [$this,'onClose'];
        $this->server->onmessage = [$this,'onmessage'];
    }

    private function makeFlvPushUrl(string $streamId): string
    {
        return str_replace('{streamId}', rawurlencode($streamId), $this->wsFlvPushUrl);
    }

    private function closeFlvRelay(int $clientId, WebRTCServer $srv): void
    {
        if (!isset($this->flvRelays[$clientId])) {
            return;
        }
        try {
            $this->flvRelays[$clientId]->finish();
        } catch (\Throwable $e) {
            $srv->_log_std("[ws-flv] client={$clientId} close failed: {$e->getMessage()}\n");
        }
        unset($this->flvRelays[$clientId]);
    }

    public function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }
        $this->shuttingDown = true;
        foreach (array_keys($this->flvRelays) as $clientId) {
            try {
                $this->flvRelays[$clientId]->finish();
            } catch (\Throwable $e) {
                $this->server->_log_std("[ws-flv] client={$clientId} shutdown failed: {$e->getMessage()}\n");
            }
            unset($this->flvRelays[$clientId]);
        }
        OpusWorkerClient::shutdownOwnedWorkers();
    }

    public function registerShutdownHandlers(): void
    {
        register_shutdown_function([$this, 'shutdown']);
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (int $event): bool {
                if ($event === PHP_WINDOWS_EVENT_CTRL_C || $event === PHP_WINDOWS_EVENT_CTRL_BREAK) {
                    $this->shutdown();
                    exit(0);
                }
                return false;
            });
        } elseif (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void {
                $this->shutdown();
                exit(0);
            });
            pcntl_signal(SIGTERM, function (): void {
                $this->shutdown();
                exit(0);
            });
        }
    }

    public function onOpen($label, $clientId, WebRTCServer $srv)
    {
        $total = count($srv->getClientIds());
        $msg = "[onOpen] new clientId={$clientId} label={$label} 当前连接总数={$total}\n";
        echo $msg;
        $srv->_log_std($msg);
    }

    public function onJoin(int $clientId, array $msg, WebRTCServer $srv, &$handled)
    {
        $role = (string)($msg['role'] ?? '');
        $streamId = (string)($msg['streamId'] ?? '');
        if ($streamId === '' || !in_array($role, ['push', 'play'], true)) {
            return;
        }
        if (!isset($this->rooms[$streamId])) {
            $this->rooms[$streamId] = [
                'pushId' => null,
                'subscribers' => [],
                'createdAt' => time(),
            ];
        }

        if ($role === 'push') {
            if ($this->rooms[$streamId]['pushId'] !== null && $this->rooms[$streamId]['pushId'] !== $clientId) {
                $oldId = $this->rooms[$streamId]['pushId'];
                $srv->_log_std("[onJoin] streamId={$streamId} 新推流端 {$clientId} 顶掉旧的 {$oldId}\n");
            }
            $this->rooms[$streamId]['pushId'] = $clientId;
            $msg = "[onJoin] client={$clientId} 作为推流端加入房间 streamId={$streamId}\n";
            echo $msg;
            $srv->_log_std($msg);
        } else {
            $this->rooms[$streamId]['subscribers'][$clientId] = true;
            $srv->setClientMeta($clientId, 'clientOffer', !empty($msg['clientOffer']));
            $pushId = $this->rooms[$streamId]['pushId'];
            $viewerCnt = count($this->rooms[$streamId]['subscribers']);
            $msg = "[onJoin] client={$clientId} 作为观众加入房间 streamId={$streamId} 当前观众数={$viewerCnt} 推流端=" . ($pushId === null ? '无' : $pushId) . "\n";
            echo $msg;
            $srv->_log_std($msg);
        }

    }

    public function onPublisher(int $clientId, array $ctx, WebRTCServer $srv)
    {
        $streamId = (string)($ctx['streamId'] ?? '');
        $localSsrc = $ctx['localSsrc'] ?? [];
        $videoPTs = array_keys($ctx['videoPTs'] ?? []);
        $audioPTs = array_keys($ctx['audioPTs'] ?? []);

        if ($streamId !== '') {
            if (!isset($this->rooms[$streamId])) {
                $this->rooms[$streamId] = [
                    'pushId' => null,
                    'subscribers' => [],
                    'createdAt' => time(),
                ];
                $srv->_log_std("[onPublisher] WHIP推流端创建房间 streamId={$streamId} (onJoin未触发)\n");
            }
            $this->rooms[$streamId]['pushId'] = $clientId;
            $this->rooms[$streamId]['publisherReadyAt'] = time();
        }

        echo "[onPublisher] 推流端就绪 clientId={$clientId} streamId={$streamId} "
            . "videoSSRC=" . ($localSsrc['video'] ?? '?') . " audioSSRC=" . ($localSsrc['audio'] ?? '?')
            . " videoPTs=[" . implode(',', $videoPTs) . "] audioPTs=[" . implode(',', $audioPTs) . "]\r\n";
        $_msg = "[onPublisher] 推流端就绪 clientId={$clientId} streamId={$streamId} "
            . "videoSSRC=" . ($localSsrc['video'] ?? '?') . " audioSSRC=" . ($localSsrc['audio'] ?? '?')
            . " videoPTs=[" . implode(',', $videoPTs) . "] audioPTs=[" . implode(',', $audioPTs) . "]\n";
        $srv->_log_std($_msg);

        if ($this->webrtc2rtmp && $streamId !== '') {
            foreach ($this->flvRelays as $oldClientId => $relay) {
                if ($relay->streamId() === $streamId && $oldClientId !== $clientId) {
                    $this->closeFlvRelay((int)$oldClientId, $srv);
                }
            }
            $existing = $this->flvRelays[$clientId] ?? null;
            if ($existing !== null && ($existing->streamId() !== $streamId || !$existing->isHealthy())) {
                $this->closeFlvRelay($clientId, $srv);
                $existing = null;
            }
            if ($existing === null) {
                $relay = null;
                try {
                    $relay = new WebRtcFlvRelay($clientId, $streamId, $this->makeFlvPushUrl($streamId), null, null, $this->opusWorkerPort);
                    $relay->connect();
                    $this->flvRelays[$clientId] = $relay;
                    $srv->_log_std("[ws-flv] relay connected client={$clientId} streamId={$streamId}\n");
                } catch (\Throwable $e) {
                    if ($relay !== null) {
                        try {
                            $relay->finish();
                        } catch (\Throwable $ignored) {
                        }
                    }
                    $srv->_log_std("[ws-flv] relay connect failed client={$clientId} streamId={$streamId}: {$e->getMessage()}\n");
                }
            }
        }

        if ($streamId !== '' && isset($this->rooms[$streamId])) {
            $subIds = array_keys($this->rooms[$streamId]['subscribers'] ?? []);
            foreach ($subIds as $subId) {
                $subId = (int)$subId;
                if ($srv->getClientMeta($subId, 'clientOffer', false)) {
                    $srv->_log_std("[onPublisher] subscriberId={$subId} 使用浏览器 Offer，跳过服务端 SFU Offer\n");
                    continue;
                }
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
    }

    public function onSubscriber(int $clientId, array $ctx, WebRTCServer $srv)
    {
        $streamId = (string)($ctx['streamId'] ?? '');
        $pushId   = $ctx['pushClientId'] ?? null;

        $viewerCnt = isset($this->rooms[$streamId]['subscribers']) ? count($this->rooms[$streamId]['subscribers']) : 0;
        echo "[onSubscriber] 新订阅者 clientId={$clientId} streamId={$streamId} 推流端=" . ($pushId === null ? '等待中' : $pushId) . " 当前观众数={$viewerCnt}\r\n";
        $_msg = "[onSubscriber] 新订阅者 clientId={$clientId} streamId={$streamId} 推流端=" . ($pushId === null ? '等待中' : $pushId) . " 当前观众数={$viewerCnt}\n";
        $srv->_log_std($_msg);

        $srv->setClientMeta($clientId, 'subscriberHandled', 'true');

        $offerSent = false;
        $clientOffer = (bool)$srv->getClientMeta($clientId, 'clientOffer', false);
        if ($streamId !== '' && !$clientOffer) {

            $currentPushId = $pushId;
            if ($currentPushId === null && isset($this->rooms[$streamId])) {
                $currentPushId = $this->rooms[$streamId]['pushId'] ?? null;
            }
            if ($currentPushId !== null && isset($srv->clients[$currentPushId])) {
                $offer = $srv->makeSfuOfferForSubscriber($clientId, (int)$currentPushId);
                if ($offer !== null) {
                    $ok = $srv->sendSignaling($clientId, ['type' => 'offer', 'sdp' => $offer]);
                    $srv->_log_std("[onSubscriber] subscriberId={$clientId} streamId={$streamId} <- SFU offer sent immediately (push={$currentPushId}, len=" . strlen($offer) . ", send=" . ($ok?'ok':'fail') . ")\n");
                    $offerSent = true;
                } else {
                    $srv->_log_std("[onSubscriber] subscriberId={$clientId} makeSfuOfferForSubscriber(push={$currentPushId}) FAIL, wait onPublisher to re-fire\n");
                }
            }
        }

        if (!$clientOffer) {
            $kick1 = $srv->kickFaststartForSubscriber($clientId);
            $srv->_log_std("[onSubscriber] subscriberId={$clientId} kickFaststart(T+0 join) pliSent=" . ($kick1['pliSent']?'yes':'no') . " gopBurst=" . (int)$kick1['gopBurst'] . " offerSent=" . ($offerSent?'yes':'no') . "\n");
        } else {
            $srv->_log_std("[onSubscriber] subscriberId={$clientId} 等待浏览器 Offer 完成后 kickFaststart\n");
        }
    }

    public function onOffer(int $clientId, string $offerSdp, string $answerSdp, WebRTCServer $srv)
    {
        $role = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
        $msg = "[onOffer] client={$clientId} role={$role} offer len=" . strlen($offerSdp) . " answer len=" . strlen($answerSdp) . "\n";
        echo $msg;
        $srv->_log_std($msg);
    }

    public function onAnswer(int $clientId, string $sdp, WebRTCServer $srv, &$handled)
    {
        $role     = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
        $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');
        $msg = "[onAnswer] client={$clientId} role={$role} streamId={$streamId} answer sdp len=" . strlen($sdp) . "\n";
        echo $msg;
        $srv->_log_std($msg);

        if ($role === 'play' && $streamId !== '') {
            $kick2 = $srv->kickFaststartForSubscriber($clientId);
            $srv->_log_std("[onAnswer] subscriberId={$clientId} kickFaststart(T+answer) pliSent=" . ($kick2['pliSent']?'yes':'no') . " gopBurst=" . (int)$kick2['gopBurst'] . "\n");
        }
    }

    public function onCandidate(int $clientId, array $msg, WebRTCServer $srv, &$handled)
    {
        $role     = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
        $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');
        $cand     = (string)($msg['candidate'] ?? '');
        $msg2 = "[onCandidate] client={$clientId} role={$role} streamId={$streamId} candidate len=" . strlen($cand) . "\n";
        echo $msg2;
        $srv->_log_std($msg2);
    }

    public function onMediaConnected(int $clientId, array $rtp, WebRTCServer $srv)
    {
        $pt       = (int)($rtp['pt'] ?? -1);
        $ssrc     = (int)($rtp['ssrc'] ?? 0);
        $seq      = (int)($rtp['seq'] ?? 0);
        $role     = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
        $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');

        $videoPTs = $srv->clients[$clientId]['videoPTs'] ?? [];
        $audioPTs = $srv->clients[$clientId]['audioPTs'] ?? [];
        $kind = isset($videoPTs[$pt]) ? 'video' : (isset($audioPTs[$pt]) ? 'audio' : 'unknown');

        $msg = "[onMediaConnected] 媒体首帧 client={$clientId} role={$role} streamId={$streamId} "
            . "kind={$kind} pt={$pt} ssrc={$ssrc} seq={$seq}\n";
        echo $msg;
        $srv->_log_std($msg);
    }

    public function onSignaling(int $clientId, array $msg, WebRTCServer $srv, &$handled)
    {
        $type = (string)($msg['type'] ?? '?');
        echo "[onSignaling] client={$clientId} type={$type}\r\n";
    }

    public function onRtp(int $clientId, string $plainRtp, array $header, WebRTCServer $srv)
    {
        static $ssrcKindLogOnce = [];

        $role = (string)$srv->getClientMeta($clientId, 'role', '');
        $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');
        try {
            if ($role === 'push' && $streamId !== '') {
                $srv->forwardRtpToAllSubscribers($streamId, $plainRtp, $clientId);
            } else {
                $srv->forwardRtpToClient($clientId, $plainRtp);
            }
        } catch (\Throwable $e) {
            $srv->_log_std("[onRtp] default RTP path failed client={$clientId}: {$e->getMessage()}\n");
        }

        if (!$this->webrtc2rtmp || $role !== 'push' || $streamId === '' || !isset($this->flvRelays[$clientId])) {
            return;
        }
        try {
            $pt = (int)($header['pt'] ?? -1);
            $ssrc = (int)($header['ssrc'] ?? 0);
            $client = $srv->clients[$clientId] ?? [];
            $kind = null;
            if (isset($client['videoPTs'][$pt])) {
                $info = $client['videoPTs'][$pt];
                $codec = strtolower(is_array($info) ? (string)($info['codec'] ?? $info['rtpmap'] ?? '') : (string)$info);
                if ($codec === '' || strpos($codec, 'h264') === 0 || strpos($codec, 'avc') === 0) {
                    $kind = 'video';
                }
            } elseif (isset($client['audioPTs'][$pt])) {
                $info = $client['audioPTs'][$pt];
                $codec = strtolower(is_array($info) ? (string)($info['codec'] ?? $info['rtpmap'] ?? '') : (string)$info);
                if ($codec === '' || strpos($codec, 'opus') === 0) {
                    $kind = 'audio';
                }
            }

            // OBS 与浏览器的动态 Payload Type 映射可能不同，PT 无法匹配时按已记录的 SSRC 兜底。
            if ($kind === null && $ssrc > 1) {
                $incomingSsrcByKind = is_array($client['incomingSsrcByKind'] ?? null)
                    ? $client['incomingSsrcByKind']
                    : [];
                if ($ssrc === (int)($incomingSsrcByKind['video'] ?? 0)) {
                    $kind = 'video';
                } elseif ($ssrc === (int)($incomingSsrcByKind['audio'] ?? 0)) {
                    $kind = 'audio';
                }
                if ($kind !== null) {
                    $logKey = $clientId . ':' . $pt . ':' . $kind;
                    if (!isset($ssrcKindLogOnce[$logKey])) {
                        $ssrcKindLogOnce[$logKey] = true;
                        $srv->_log_std(sprintf(
                            "[ws-flv] RTP kind resolved by SSRC client=%d streamId=%s pt=%d ssrc=%d kind=%s\n",
                            $clientId,
                            $streamId,
                            $pt,
                            $ssrc,
                            $kind
                        ));
                    }
                }
            }

            if ($kind !== null) {
                $relay = $this->flvRelays[$clientId];
                $relay->pushRtp($plainRtp, $kind);
                $opusFormat = $relay->consumeOpusFormat();
                if ($opusFormat !== null) {
                    $srv->_log_std(sprintf(
                        "[ws-flv] Opus format client=%d streamId=%s toc=0x%02X mode=%s bandwidth=%s codedChannels=%d frameSamples=%d frameCount=%d packetBytes=%d\n",
                        $clientId,
                        $streamId,
                        $opusFormat['toc'],
                        $opusFormat['mode'],
                        $opusFormat['bandwidth'],
                        $opusFormat['stereo'] ? 2 : 1,
                        $opusFormat['frameDurationSamples'],
                        $opusFormat['frameCount'],
                        $opusFormat['packetBytes']
                    ));
                }
                if ($relay->consumeAvcSequenceHeaderSent()) {
                    $srv->_log_std("[ws-flv] AVC sequence header sent client={$clientId} streamId={$streamId}\n");
                }
            }
        } catch (\Throwable $e) {
            $srv->_log_std("[ws-flv] RTP relay failed client={$clientId} streamId={$streamId}: {$e->getMessage()}\n");
            $this->closeFlvRelay($clientId, $srv);
        }
    }


    public function onLeave(int $clientId, WebRTCServer $srv)
    {
        $role     = (string)$srv->getClientMeta($clientId, 'role', '');
        $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');

        if ($role === 'push') {
            $this->closeFlvRelay($clientId, $srv);
        }

        if ($streamId !== '' && isset($this->rooms[$streamId])) {
            if ($role === 'push') {
                if (($this->rooms[$streamId]['pushId'] ?? null) === $clientId) {
                    $this->rooms[$streamId]['pushId'] = null;
                    $msg = "[onLeave] 推流端 client={$clientId} 离开房间 streamId={$streamId} (无推流)\n";
                    echo $msg;
                    $srv->_log_std($msg);

                    $subIds = array_keys($this->rooms[$streamId]['subscribers'] ?? []);
                    if (!empty($subIds)) {
                        $srv->broadcastSignaling($subIds, ['type' => 'publisher-left', 'streamId' => $streamId]);
                    }
                }
            } elseif ($role === 'play') {
                if (isset($this->rooms[$streamId]['subscribers'][$clientId])) {
                    unset($this->rooms[$streamId]['subscribers'][$clientId]);
                }
                $viewerCnt = count($this->rooms[$streamId]['subscribers'] ?? []);
                $msg = "[onLeave] 观众 client={$clientId} 离开房间 streamId={$streamId} 当前观众数={$viewerCnt}\n";
                echo $msg;
                $srv->_log_std($msg);
            }

            if (($this->rooms[$streamId]['pushId'] ?? null) === null && empty($this->rooms[$streamId]['subscribers'])) {
                unset($this->rooms[$streamId]);
                $msg = "[onLeave] 空房间已回收 streamId={$streamId}\n";
                echo $msg;
                $srv->_log_std($msg);
            }
        } else {
            $msg = "[onLeave] 连接关闭 client={$clientId}\n";
            echo $msg;
            $srv->_log_std($msg);
        }
    }

    public function onClose($id, WebRTCServer $srv)
    {
        $clientId = (int)$id;
        $this->closeFlvRelay($clientId, $srv);
        foreach ($this->rooms as $streamId => &$room) {
            if (($room['pushId'] ?? null) === $clientId) {
                $room['pushId'] = null;
            }
            unset($room['subscribers'][$clientId]);
            if (($room['pushId'] ?? null) === null && empty($room['subscribers'])) {
                unset($this->rooms[$streamId]);
            }
        }
        unset($room);
        echo "[onClose] WebSocket closed client={$id}\r\n";
    }

    public function onmessage(string $message, int $clientId, WebRTCServer $srv)
    {
        $trimMsg  = trim($message);
        $role     = (string)$srv->getClientMeta($clientId, 'role', 'unknown');
        $streamId = (string)$srv->getClientMeta($clientId, 'streamId', '');
        $label    = (string)$srv->getClientMeta($clientId, 'label', 'client#'.$clientId);

        $srv->_log_std("[onmessage] client={$clientId} label={$label} role={$role} streamId={$streamId} msg=\"{$trimMsg}\"\n");
        $reply = "服务器收到：\"{$trimMsg}\" （时间:" . date('H:i:s') . " | clientId={$clientId} | role={$role}）";
        $ok = $srv->sendDataChannel($clientId, $reply);
        $srv->_log_std("[onmessage] client={$clientId} send reply ok=" . ($ok ? 'YES' : 'NO') . " reply=\"{$reply}\"\n");

        if ($streamId !== '' && isset($this->rooms[$streamId])) {
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
    }
}