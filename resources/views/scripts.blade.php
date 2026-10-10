<script>
    (() => {
        if (window.__liveQueryResyncBound) return;
        window.__liveQueryResyncBound = true;

        let livewireReady = false;
        let pendingResync = false;
        const resync = () => {
            if (!livewireReady || !window.Livewire) {
                pendingResync = true;
                return;
            }

            window.Livewire.dispatch('livequery-resync');
        };

        document.addEventListener('livewire:initialized', () => {
            livewireReady = true;
            if (pendingResync) resync();
        }, { once: true });

        document.addEventListener('livewire:navigated', resync);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') resync();
        });

        let connectionBound = false;
        const bindConnection = () => {
            if (connectionBound) return true;

            const connection = window.Echo?.connector?.pusher?.connection;
            if (!connection) return false;

            connectionBound = true;
            connection.bind('state_change', ({ current }) => {
                if (current === 'connected') resync();
            });

            if (connection.state === 'connected') resync();

            return true;
        };

        if (!bindConnection()) {
            document.addEventListener('livewire:init', bindConnection, { once: true });
        }
    })();
</script>
