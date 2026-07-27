registerAction("editNewsInline", (el, event) => {
    // Verhindern, dass der Klick weiter nach oben steigt
    if (event) event.stopPropagation();

    const newsId = el.dataset.id;
    const oldTitle = el.dataset.title;
    const oldContent = el.dataset.content;

    const newsBox = el.closest('.box-container');
    const contentDiv = newsBox.querySelector('.news-content');
    const headerTitle = newsBox.querySelector('.news-header-title');

    // Falls bereits ein Formular offen ist, nichts tun
    if (contentDiv.querySelector('form')) return;

    const formHtml = `
        <form method="POST" id="edit-news-form-${newsId}" style="width: 100%; text-align: left; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 5px;">
            <input type="hidden" name="news_id" value="${newsId}">
            <input type="hidden" name="edit_news" value="1">
            
            <label style="font-size: 14px; color: var(--link-color);">Titel:</label><br>
            <input type="text" name="title" id="edit-news-title-${newsId}" value="${oldTitle}" 
                   maxlength="50" style="width: 100%; margin-bottom: 15px;" required>
            
            <label style="font-size: 14px; color: var(--link-color);">Inhalt:</label><br>
            <textarea name="content" id="edit-news-text-${newsId}" rows="8" maxlength="2000" 
                      style="width: 100%; margin-bottom: 15px; resize: vertical;" required>${oldContent}</textarea>
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <input type="submit" value="Änderungen speichern">
                <input type="button" value="Abbrechen" data-on-click="cancelNewsEdit">
            </div>
        </form>
    `;

    headerTitle.innerText = "Beitrag bearbeiten";
    contentDiv.innerHTML = formHtml;

    const tools = newsBox.querySelector('.news-admin-tools');
    if (tools) tools.style.display = 'none';

    // FIX: Fokus mit minimaler Verzögerung setzen
    setTimeout(() => {
        const textarea = document.getElementById(`edit-news-text-${newsId}`);
        if (textarea) {
            textarea.focus();
            // Cursor ans Ende setzen
            const val = textarea.value;
            textarea.value = '';
            textarea.value = val;
        }
    }, 50);

    const handleKeyDown = (e) => {
        if (e.key === "Escape") {
            document.removeEventListener("keydown", handleKeyDown);
            window.location.reload();
        }
    };
    document.addEventListener("keydown", handleKeyDown);
});
registerAction("cancelNewsEdit", () => {
    cancelEdit();
});

function cancelEdit() {
    window.location.reload();
}