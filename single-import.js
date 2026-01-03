jQuery(document).ready(function($) {
    let searchTimeout;
    let selectedBuildingId = null;

    // Building search with autocomplete
    $('#building-search').on('input', function() {
        const searchTerm = $(this).val().trim();

        clearTimeout(searchTimeout);

        if (searchTerm.length < 2) {
            $('#building-search-results').html('').hide();
            return;
        }

        searchTimeout = setTimeout(function() {
            searchBuildings(searchTerm);
        }, 300);
    });

    function searchBuildings(searchTerm) {
        $.ajax({
            url: BuildingSingleImport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'search_buildings',
                nonce: BuildingSingleImport.nonce,
                search: searchTerm
            },
            beforeSend: function() {
                $('#building-search-results').html('<div class="search-loading">Searching...</div>').show();
            },
            success: function(response) {
                if (response.success && response.data.buildings.length > 0) {
                    displaySearchResults(response.data.buildings);
                } else {
                    $('#building-search-results').html('<div class="no-results">No buildings found</div>').show();
                }
            },
            error: function() {
                $('#building-search-results').html('<div class="error">Search failed</div>').show();
            }
        });
    }

    function displaySearchResults(buildings) {
        let html = '<ul class="building-results-list">';
        buildings.forEach(function(building) {
            html += `<li class="building-result-item" data-building-id="${building.id}">
                <strong>${escapeHtml(building.title)}</strong>
                <br><small><a href="${building.url}" target="_blank">View building</a></small>
            </li>`;
        });
        html += '</ul>';
        $('#building-search-results').html(html).show();
    }

    // Select building from search results
    $(document).on('click', '.building-result-item', function() {
        selectedBuildingId = $(this).data('building-id');
        const buildingTitle = $(this).find('strong').text();

        $('#building-search').val(buildingTitle);
        $('#building-search-results').hide();

        loadBuildingImages(selectedBuildingId);
    });

    // Close search results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#building-search-container').length) {
            $('#building-search-results').hide();
        }
    });

    function loadBuildingImages(buildingId) {
        $.ajax({
            url: BuildingSingleImport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_building_images',
                nonce: BuildingSingleImport.nonce,
                building_id: buildingId
            },
            beforeSend: function() {
                $('#building-details').show();
                $('#building-title').text('Loading...');
                $('#building-images').html('<p>Loading images...</p>');
                $('#import-status').html('');
            },
            success: function(response) {
                if (response.success) {
                    displayBuildingImages(response.data);
                } else {
                    $('#building-images').html('<p class="error">' + response.data.message + '</p>');
                }
            },
            error: function() {
                $('#building-images').html('<p class="error">Failed to load images</p>');
            }
        });
    }

    function displayBuildingImages(data) {
        $('#building-title').text(data.building_title);

        let infoHtml = `<p><strong>Tag:</strong> <code>${escapeHtml(data.tag)}</code></p>`;
        if (data.has_thumbnail) {
            infoHtml += '<p><strong>Status:</strong> <span class="has-thumbnail">Featured image already set</span></p>';
        } else {
            infoHtml += '<p><strong>Status:</strong> <span class="no-thumbnail">No featured image</span></p>';
        }
        $('#building-info').html(infoHtml);

        if (data.images.length === 0) {
            $('#building-images').html('<p>No images found for this building with tag: <code>' + escapeHtml(data.tag) + '</code></p>');
            return;
        }

        let html = '<div class="images-grid">';
        data.images.forEach(function(image) {
            html += `
                <div class="image-item">
                    <img src="${image.src}" alt="${escapeHtml(image.alttext)}" class="image-preview">
                    <div class="image-details">
                        <strong>${escapeHtml(image.filename)}</strong>
                        <p>${escapeHtml(image.description)}</p>
                        <p><em>${escapeHtml(image.alttext)}</em></p>
                        <button class="button button-primary import-image-btn" data-image-pid="${image.pid}" data-building-id="${data.building_id}">
                            Import & Set as Featured
                        </button>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        if (data.images.length > 1) {
            html = '<button class="button button-secondary import-all-btn" data-building-id="' + data.building_id + '">Import All Images</button>' + html;
        }

        $('#building-images').html(html);
    }

    // Import single image
    $(document).on('click', '.import-image-btn', function() {
        const btn = $(this);
        const imagePid = btn.data('image-pid');
        const buildingId = btn.data('building-id');

        importImage(buildingId, imagePid, btn);
    });

    // Import all images
    $(document).on('click', '.import-all-btn', function() {
        const btn = $(this);
        const buildingId = btn.data('building-id');
        const imageButtons = $('.import-image-btn');

        if (!confirm('Import all ' + imageButtons.length + ' images? Only the first will be set as featured image.')) {
            return;
        }

        btn.prop('disabled', true).text('Importing all...');

        let importPromises = [];
        imageButtons.each(function() {
            const imagePid = $(this).data('image-pid');
            importPromises.push(importImagePromise(buildingId, imagePid, $(this)));
        });

        Promise.all(importPromises).then(function() {
            btn.prop('disabled', false).text('Import All Images');
            showStatus('All images imported successfully!', 'success');
        }).catch(function() {
            btn.prop('disabled', false).text('Import All Images');
            showStatus('Some images failed to import', 'error');
        });
    });

    function importImage(buildingId, imagePid, btn) {
        $.ajax({
            url: BuildingSingleImport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'import_building_image',
                nonce: BuildingSingleImport.nonce,
                building_id: buildingId,
                image_pid: imagePid
            },
            beforeSend: function() {
                btn.prop('disabled', true).text('Importing...');
            },
            success: function(response) {
                if (response.success) {
                    btn.prop('disabled', false).text('✓ Imported');
                    btn.removeClass('button-primary').addClass('button-secondary');

                    const message = response.data.reused ?
                        'Image reused from media library and set as featured image!' :
                        'Image imported and set as featured image!';
                    showStatus(message, 'success');
                } else {
                    btn.prop('disabled', false).text('Failed');
                    showStatus('Import failed: ' + response.data.message, 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Error');
                showStatus('Import failed', 'error');
            }
        });
    }

    function importImagePromise(buildingId, imagePid, btn) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: BuildingSingleImport.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'import_building_image',
                    nonce: BuildingSingleImport.nonce,
                    building_id: buildingId,
                    image_pid: imagePid
                },
                beforeSend: function() {
                    btn.prop('disabled', true).text('Importing...');
                },
                success: function(response) {
                    if (response.success) {
                        btn.prop('disabled', false).text('✓ Imported');
                        btn.removeClass('button-primary').addClass('button-secondary');
                        resolve(response);
                    } else {
                        btn.prop('disabled', false).text('Failed');
                        reject(response);
                    }
                },
                error: function(error) {
                    btn.prop('disabled', false).text('Error');
                    reject(error);
                }
            });
        });
    }

    function showStatus(message, type) {
        const className = type === 'success' ? 'notice-success' : 'notice-error';
        $('#import-status').html(`<div class="notice ${className} is-dismissible"><p>${escapeHtml(message)}</p></div>`);

        setTimeout(function() {
            $('#import-status').fadeOut(function() {
                $(this).html('').show();
            });
        }, 5000);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
