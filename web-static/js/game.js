(async function () {
    const user = await getCurrentUser();
    if (!user) {
        window.location.replace('/');
        return;
    }

    document.getElementById('username').textContent = user.username;
    document.getElementById('game-main').hidden = false;

    document.getElementById('logout-button').addEventListener('click', async () => {
        await logout();
        window.location.replace('/');
    });
})();
