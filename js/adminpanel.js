function editField(userID, fieldID, currentValue, formattedValue) {
    // Get the table cell by ID
    const td = document.getElementById('td_' + fieldID);

    // Create a new input element
    /** @type {HTMLInputElement} */
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue;

    // Create a hidden form to submit the new value
    /** @type {HTMLFormElement} */
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    // Create hidden inputs for the field, user ID and current/new values for the fields
    const hiddenField = document.createElement('input');
    hiddenField.type = 'hidden';
    hiddenField.name = 'field';
    hiddenField.value = fieldID;

    const hiddenUserID = document.createElement('input');
    hiddenUserID.type = 'hidden';
    hiddenUserID.name = 'user_id';
    hiddenUserID.value = userID;

    const hiddenNewValue = document.createElement('input');
    hiddenNewValue.type = 'hidden';
    hiddenNewValue.name = 'new_value';
    hiddenNewValue.value = currentValue;

    const hiddenCurrentValue = document.createElement('input');
    hiddenCurrentValue.type = 'hidden';
    hiddenCurrentValue.name = 'old_value';
    hiddenCurrentValue.value = currentValue;

    form.appendChild(hiddenField);
    form.appendChild(hiddenUserID);
    form.appendChild(hiddenNewValue);
    form.appendChild(hiddenCurrentValue);
    form.appendChild(input);

    // Clear the current cell and append the form
    td.innerHTML = '';
    td.appendChild(form);

    // Add event listeners for the input field
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            hiddenNewValue.value = input.value;
            form.submit();
        } else if (event.key === 'Escape') {
            cancelEdit(td, formattedValue);
        }
    });

    // When focus is lost: cancel the edit
    input.addEventListener('blur', function () {
        cancelEdit(td, formattedValue);
    });

    input.focus();
}

function cancelEdit(td, originalValue) {
    td.innerHTML = originalValue;
}

function userDeletionDialog(userID) {
    showConfirmationDialog(
        'Willst du den Benutzer wirklich löschen?',
        'Ja',
        'Nein',
        () => {
            deleteUser('adminpanel.php?deleteuser=' + userID);
        }
    );
}

function deleteUser(url) {
    window.location.href = url;
}