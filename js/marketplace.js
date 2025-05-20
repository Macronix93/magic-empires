document.addEventListener("DOMContentLoaded", function () {
    const supplySelect = document.querySelector("select[name='s']");
    const demandSelect = document.querySelector("select[name='d']");

    function updateDropdowns() {
        let supplyValue = supplySelect.value;
        let demandValue = demandSelect.value;

        // Enable all options first and deselect them
        Array.from(supplySelect.options).forEach(option => {
            option.hidden = false;
            option.selected = false;
        });
        Array.from(demandSelect.options).forEach(option => {
            option.hidden = false;
            option.selected = false;
        });

        // Hide the selected supply from demand dropdown
        if (supplyValue) {
            let demandOption = demandSelect.querySelector(`option[value='${supplyValue}']`);
            if (demandOption) demandOption.hidden = true;
        }

        // Hide the selected demand from supply dropdown
        if (demandValue) {
            let supplyOption = supplySelect.querySelector(`option[value='${demandValue}']`);
            if (supplyOption) supplyOption.hidden = true;
        }

        // Mark the current selection as selected
        Array.from(supplySelect.options).forEach(option => {
            if (option.value === supplyValue) {
                option.selected = true;
            }
        });

        Array.from(demandSelect.options).forEach(option => {
            if (option.value === demandValue) {
                option.selected = true;
            }
        });
    }

    supplySelect.addEventListener("change", updateDropdowns);
    demandSelect.addEventListener("change", updateDropdowns);

    // Initial call
    updateDropdowns();
});
