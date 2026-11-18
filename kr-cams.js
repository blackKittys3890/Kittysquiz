// Version 2.1
// Gemeinsame Kamera-, Mikrofon- und Spielerlogik für raum.php und board.php
const KRCams = (() => {
    const ICE_CONFIG = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };

    let roomCode = '';
    let myId = '';
    let onUpdate = () => {};
    let players = [];

    let camStream = null;
    let camTrack = null;
    let micStream = null;
    let micTrack = null;

    const peers = new Map();        // id -> { pc, sender, audioSender, polite, makingOffer, ignoreOffer }
    const remoteTracks = new Map(); // id -> Video-Track dieser Person
    const remoteAudio = new Map();  // id -> <audio>-Element mit dem Ton dieser Person
    const camOn = new Map();        // id -> bool, vom Gegenüber gemeldet
    const micOnMap = new Map();     // id -> bool, vom Gegenüber gemeldet

    function sendSignal(to, kind, data) {
        const body = new URLSearchParams({
            code: roomCode,
            to: to,
            payload: JSON.stringify({ kind, data }),
        });
        return fetch('/signal.php', { method: 'POST', body }).catch(() => {});
    }

    function micOnNow() {
        return !!(micTrack && micTrack.enabled);
    }

    // Kamera- und Mikrofonstatus an alle Verbindungen senden
    function broadcastState() {
        for (const id of peers.keys()) {
            sendSignal(id, 'cam', { on: !!camStream });
            sendSignal(id, 'mic', { on: micOnNow() });
        }
    }

    function showsVideo(id) {
        if (id === myId) return !!camStream;
        return !!camOn.get(id) && remoteTracks.has(id);
    }

    function trackFor(id) {
        return id === myId ? camTrack : (remoteTracks.get(id) || null);
    }

    // Liefert ein Video-Element für diese Person oder null (dann Profilbild anzeigen)
    function createVideo(id, mirror) {
        const track = trackFor(id);
        if (!showsVideo(id) || !track) return null;
        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        video.muted = true;
        if (mirror) video.style.transform = 'scaleX(-1)';
        video.srcObject = new MediaStream([track]);
        return video;
    }

    // Ist das Mikrofon dieser Person gerade an (nicht stummgeschaltet)?
    function isMicOn(id) {
        return id === myId ? micOnNow() : !!micOnMap.get(id);
    }

    // Zustand für den Mikrofon-Button
    function micState() {
        return { started: !!micTrack, enabled: micOnNow() };
    }

    function attachAudio(id, track) {
        detachAudio(id);
        const a = new Audio();
        a.autoplay = true;
        a.srcObject = new MediaStream([track]);
        a.play().catch(() => {});
        remoteAudio.set(id, a);
    }

    function detachAudio(id) {
        const a = remoteAudio.get(id);
        if (a) {
            a.pause();
            a.srcObject = null;
            remoteAudio.delete(id);
        }
    }

    function getPeer(id) {
        if (peers.has(id)) return peers.get(id);

        const pc = new RTCPeerConnection(ICE_CONFIG);
        const entry = { pc, sender: null, audioSender: null, polite: myId < id, makingOffer: false, ignoreOffer: false };
        peers.set(id, entry);

        // Ein Video- und ein Audio-Kanal in beide Richtungen
        const videoT = pc.addTransceiver('video', { direction: 'sendrecv' });
        entry.sender = videoT.sender;
        if (camTrack) videoT.sender.replaceTrack(camTrack).catch(() => {});

        const audioT = pc.addTransceiver('audio', { direction: 'sendrecv' });
        entry.audioSender = audioT.sender;
        if (micTrack) audioT.sender.replaceTrack(micTrack).catch(() => {});

        pc.onnegotiationneeded = async () => {
            try {
                entry.makingOffer = true;
                await pc.setLocalDescription();
                sendSignal(id, 'sdp', pc.localDescription);
            } catch (e) {
                console.warn('Negotiation-Fehler', e);
            } finally {
                entry.makingOffer = false;
            }
        };

        pc.onicecandidate = ({ candidate }) => {
            if (candidate) sendSignal(id, 'ice', candidate);
        };

        pc.ontrack = ({ track }) => {
            if (track.kind === 'audio') {
                attachAudio(id, track);
                track.onended = () => detachAudio(id);
                return;
            }
            remoteTracks.set(id, track);
            track.onended = () => {
                remoteTracks.delete(id);
                onUpdate(players);
            };
            onUpdate(players);
        };

        // Dem neuen Gegenüber sofort meinen Kamera- und Mikrofonstatus mitteilen
        sendSignal(id, 'cam', { on: !!camStream });
        sendSignal(id, 'mic', { on: micOnNow() });

        return entry;
    }

    async function handleSignal(from, kind, data) {
        const e = getPeer(from);
        const pc = e.pc;
        try {
            if (kind === 'cam') {
                camOn.set(from, !!data.on);
                onUpdate(players);
            } else if (kind === 'mic') {
                micOnMap.set(from, !!data.on);
                onUpdate(players);
            } else if (kind === 'sdp') {
                const collision = data.type === 'offer' &&
                    (e.makingOffer || pc.signalingState !== 'stable');
                e.ignoreOffer = !e.polite && collision;
                if (e.ignoreOffer) return;

                await pc.setRemoteDescription(data);
                if (data.type === 'offer') {
                    await pc.setLocalDescription();
                    sendSignal(from, 'sdp', pc.localDescription);
                }
            } else if (kind === 'ice') {
                try {
                    await pc.addIceCandidate(data);
                } catch (err) {
                    if (!e.ignoreOffer) throw err;
                }
            }
        } catch (err) {
            console.warn('Signal-Fehler', err);
        }
    }

    async function pollSignals() {
        try {
            const res = await fetch('/signal.php?code=' + encodeURIComponent(roomCode), { cache: 'no-store' });
            if (res.ok) {
                const msgs = await res.json();
                for (const m of msgs) {
                    const { kind, data } = JSON.parse(m.payload);
                    await handleSignal(m.from_id, kind, data);
                }
            }
        } catch (e) {
            // nächster Durchlauf versucht es erneut
        }
        setTimeout(pollSignals, 1000);
    }

    function syncPeers() {
        const ids = new Set(players.map(p => p.discord_id).filter(id => id !== myId));
        ids.forEach(id => getPeer(id));

        for (const [id, e] of peers) {
            if (!ids.has(id)) {
                e.pc.close();
                peers.delete(id);
                remoteTracks.delete(id);
                camOn.delete(id);
                micOnMap.delete(id);
                detachAudio(id);
            }
        }
    }

    async function pollPlayers() {
        try {
            const res = await fetch('/players.php?code=' + encodeURIComponent(roomCode), { cache: 'no-store' });
            if (res.ok) {
                players = await res.json();
                syncPeers();
                onUpdate(players);
            }
        } catch (e) {
            // nächster Durchlauf versucht es erneut
        }
        setTimeout(pollPlayers, 2000);
    }

    function init(options) {
        roomCode = options.code;
        myId = options.myId;
        onUpdate = options.onUpdate || (() => {});

        pollPlayers();
        pollSignals();

        window.addEventListener('pagehide', () => {
            for (const e of peers.values()) e.pc.close();
            if (camStream) camStream.getTracks().forEach(t => t.stop());
            if (micStream) micStream.getTracks().forEach(t => t.stop());
            navigator.sendBeacon('/leave.php', new URLSearchParams({ code: roomCode }));
        });
    }

    // Eigene Kamera ein- oder ausschalten. Gibt zurück, ob sie jetzt läuft.
    async function toggle() {
        if (camStream) {
            camStream.getTracks().forEach(t => t.stop());
            camStream = null;
            camTrack = null;
        } else {
            camStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            camTrack = camStream.getVideoTracks()[0];
        }
        for (const e of peers.values()) {
            e.sender.replaceTrack(camTrack).catch(() => {});
        }
        broadcastState();
        onUpdate(players);
        return !!camStream;
    }

    // Mikrofon starten (beim ersten Mal) bzw. stumm schalten oder wieder einschalten
    async function toggleMic() {
        if (!micTrack) {
            micStream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true },
                video: false,
            });
            micTrack = micStream.getAudioTracks()[0];
            for (const e of peers.values()) {
                e.audioSender.replaceTrack(micTrack).catch(() => {});
            }
        } else {
            // Stummschalten, ohne den Track neu anzufordern
            micTrack.enabled = !micTrack.enabled;
        }
        broadcastState();
        onUpdate(players);
        return micState();
    }

    return {
        init,
        toggle,
        toggleMic,
        micState,
        isMicOn,
        showsVideo,
        trackFor,
        createVideo,
        isCamOn: () => !!camStream,
    };
})();
// Version 2.1