/**
 * ASAE Publishing Workflow — Admin JavaScript
 *
 * Handles AJAX interactions: workflow actions, trash requests, modals,
 * activity log search, autocomplete, assignments, settings.
 *
 * @package ASAE_Publishing_Workflow
 */

/* global jQuery, asaePW */
(function ($) {
    'use strict';

    /* =====================================================================
       Modal management (accessible)
       ===================================================================== */

    var modalTrigger = null;

    function openModal(modalId) {
        var $modal = $(modalId);
        modalTrigger = document.activeElement;
        $modal.removeAttr('hidden').attr('aria-hidden', 'false');
        $modal.find('textarea, input, select, button').first().trigger('focus');

        // Trap focus.
        $modal.on('keydown.asaePwModal', function (e) {
            if (e.key === 'Escape') {
                closeModal(modalId);
                return;
            }
            if (e.key !== 'Tab') return;

            var focusable = $modal.find('textarea, input, select, button, [tabindex]:not([tabindex="-1"])').filter(':visible');
            var first = focusable.first()[0];
            var last = focusable.last()[0];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });
    }

    function closeModal(modalId) {
        var $modal = $(modalId);
        $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true');
        $modal.off('keydown.asaePwModal');
        if (modalTrigger) {
            modalTrigger.focus();
            modalTrigger = null;
        }
    }

    // Close modal on overlay click or cancel button.
    $(document).on('click', '.asae-pw-modal-overlay, .asae-pw-modal-close', function () {
        $(this).closest('.asae-pw-modal').attr('hidden', 'hidden');
        if (modalTrigger) {
            modalTrigger.focus();
            modalTrigger = null;
        }
    });

    /* =====================================================================
       Workflow: Submit for Review
       ===================================================================== */

    $(document).on('click', '.asae-pw-submit-review-btn', function () {
        var postId = $(this).data('post-id');
        $('#asae-pw-submit-modal').data('post-id', postId);
        openModal('#asae-pw-submit-modal');
    });

    $(document).on('click', '#asae-pw-submit-confirm', function () {
        var postId = $('#asae-pw-submit-modal').data('post-id');
        var note = $('#asae-pw-submit-note').val();

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_submit_for_review',
            nonce: asaePW.nonces.workflow,
            post_id: postId,
            note: note
        }, function (response) {
            if (response.success) {
                closeModal('#asae-pw-submit-modal');
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
            }
        }).fail(function () {
            alert(asaePW.i18n.error);
        });
    });

    /* =====================================================================
       Workflow: Approve
       ===================================================================== */

    $(document).on('click', '.asae-pw-approve-btn', function () {
        if (!confirm(asaePW.i18n.confirm_approve)) return;

        var submissionId = $(this).data('submission-id');
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_approve_submission',
            nonce: asaePW.nonces.workflow,
            submission_id: submissionId
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            alert(asaePW.i18n.error);
            $btn.prop('disabled', false);
        });
    });

    /* =====================================================================
       Workflow: Reject
       ===================================================================== */

    $(document).on('click', '.asae-pw-reject-btn', function () {
        var submissionId = $(this).data('submission-id');
        $('#asae-pw-reject-submission-id').val(submissionId);
        openModal('#asae-pw-reject-modal');
    });

    $(document).on('click', '#asae-pw-reject-confirm', function () {
        var submissionId = $('#asae-pw-reject-submission-id').val();
        var note = $('#asae-pw-reject-note').val();

        if (!note.trim()) {
            alert(asaePW.i18n.reject_note_required);
            return;
        }

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_reject_submission',
            nonce: asaePW.nonces.workflow,
            submission_id: submissionId,
            review_note: note
        }, function (response) {
            if (response.success) {
                closeModal('#asae-pw-reject-modal');
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
            }
        }).fail(function () {
            alert(asaePW.i18n.error);
        });
    });

    /* =====================================================================
       Trash Request
       ===================================================================== */

    $(document).on('click', '.asae-pw-request-trash', function (e) {
        e.preventDefault();
        var postId = $(this).data('post-id');
        $('#asae-pw-trash-post-id').val(postId);
        openModal('#asae-pw-trash-modal');
    });

    $(document).on('click', '#asae-pw-trash-confirm', function () {
        var postId = $('#asae-pw-trash-post-id').val();
        var reason = $('#asae-pw-trash-reason').val();

        if (!reason.trim()) {
            alert(asaePW.i18n.trash_reason_required);
            return;
        }

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_request_trash',
            nonce: asaePW.nonces.trash,
            post_id: postId,
            reason: reason
        }, function (response) {
            if (response.success) {
                closeModal('#asae-pw-trash-modal');
                alert(response.data.message);
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
            }
        }).fail(function () {
            alert(asaePW.i18n.error);
        });
    });

    /* =====================================================================
       Trash Request: Admin Approve / Deny
       ===================================================================== */

    $(document).on('click', '.asae-pw-approve-trash-btn', function () {
        var requestId = $(this).data('request-id');
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_approve_trash',
            nonce: asaePW.nonces.trash,
            request_id: requestId
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
                $btn.prop('disabled', false);
            }
        });
    });

    $(document).on('click', '.asae-pw-deny-trash-btn', function () {
        var note = prompt(asaePW.i18n.reject_note_required || 'Please provide a note:');
        if (!note) return;

        var requestId = $(this).data('request-id');
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_deny_trash',
            nonce: asaePW.nonces.trash,
            request_id: requestId,
            note: note
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
                $btn.prop('disabled', false);
            }
        });
    });

    /* =====================================================================
       Shadow Draft: Create
       ===================================================================== */

    $(document).on('click', '.asae-pw-create-shadow-btn', function () {
        var postId = $(this).data('post-id');
        var $btn = $(this);
        $btn.prop('disabled', true).text(asaePW.i18n.loading);

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_create_shadow_draft',
            nonce: asaePW.nonces.workflow,
            post_id: postId
        }, function (response) {
            if (response.success && response.data.edit_url) {
                window.location.href = response.data.edit_url;
            } else {
                alert(response.data.message || asaePW.i18n.error);
                $btn.prop('disabled', false).text('Edit as Shadow Draft');
            }
        }).fail(function () {
            alert(asaePW.i18n.error);
            $btn.prop('disabled', false);
        });
    });

    /* =====================================================================
       Activity: Load More (meta box)
       ===================================================================== */

    $(document).on('click', '.asae-pw-load-more-activity', function () {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        var offset = $btn.data('offset');

        $btn.prop('disabled', true).text(asaePW.i18n.loading);

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_load_more_activity',
            nonce: asaePW.nonces.activity,
            post_id: postId,
            offset: offset
        }, function (response) {
            if (response.success) {
                $('#asae-pw-post-activity').append(response.data.html);
                var newOffset = offset + response.data.count;
                $btn.data('offset', newOffset);
                $btn.prop('disabled', false).text('Load More');

                if (newOffset >= $btn.data('total')) {
                    $btn.remove();
                }
            }
        });
    });

    /* =====================================================================
       Activity Log: Search page
       ===================================================================== */

    $(document).on('submit', '#asae-pw-activity-filter-form', function (e) {
        e.preventDefault();
        searchActivityLog(1);
    });

    $(document).on('reset', '#asae-pw-activity-filter-form', function () {
        setTimeout(function () {
            $('#asae-pw-activity-post-id').val('');
            $('#asae-pw-activity-results').html('<p class="asae-pw-hint">' + 'Use the filters above to search the activity log.' + '</p>');
        }, 10);
    });

    function searchActivityLog(page) {
        var $form = $('#asae-pw-activity-filter-form');
        var $results = $('#asae-pw-activity-results');
        var $spinner = $form.find('.spinner');

        $spinner.addClass('is-active');
        $results.html('<p>' + asaePW.i18n.loading + '</p>');

        var data = {
            action: 'asae_pw_search_activity',
            nonce: asaePW.nonces.activity,
            page: page,
            post_id: $form.find('[name="post_id"]').val(),
            user_id: $form.find('[name="user_id"]').val(),
            action_type: $form.find('[name="action_type"]').val(),
            term_id: $form.find('[name="term_id"]').val(),
            date_from: $form.find('[name="date_from"]').val(),
            date_to: $form.find('[name="date_to"]').val()
        };

        $.post(asaePW.ajaxUrl, data, function (response) {
            $spinner.removeClass('is-active');

            if (!response.success || !response.data.rows.length) {
                $results.html('<p>' + 'No results found.' + '</p>');
                return;
            }

            var html = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
            html += '<th scope="col">Date</th><th scope="col">User</th><th scope="col">Post</th>';
            html += '<th scope="col">Action</th><th scope="col">Content Area</th><th scope="col">Detail</th>';
            html += '</tr></thead><tbody>';

            $.each(response.data.rows, function (i, row) {
                html += '<tr>';
                html += '<td>' + escHtml(row.date) + '</td>';
                html += '<td>' + escHtml(row.user) + '</td>';
                html += '<td>';
                if (row.post_edit_url) {
                    html += '<a href="' + escHtml(row.post_edit_url) + '">' + escHtml(row.post_title) + '</a>';
                } else {
                    html += escHtml(row.post_title);
                }
                html += '</td>';
                html += '<td><span class="asae-pw-badge asae-pw-badge-' + escHtml(row.action_raw) + '">' + escHtml(row.action) + '</span></td>';
                html += '<td>' + escHtml(row.content_area) + '</td>';
                html += '<td>' + escHtml(row.detail || '') + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';

            // Pagination.
            if (response.data.total_pages > 1) {
                html += '<div class="tablenav-pages">';
                for (var p = 1; p <= response.data.total_pages; p++) {
                    if (p === response.data.page) {
                        html += '<span class="tablenav-pages-navspan button disabled">' + p + '</span> ';
                    } else {
                        html += '<a href="#" class="button asae-pw-activity-page" data-page="' + p + '">' + p + '</a> ';
                    }
                }
                html += '</div>';
            }

            $results.html(html);
        }).fail(function () {
            $spinner.removeClass('is-active');
            $results.html('<p class="notice notice-error">' + asaePW.i18n.error + '</p>');
        });
    }

    $(document).on('click', '.asae-pw-activity-page', function (e) {
        e.preventDefault();
        searchActivityLog($(this).data('page'));
    });

    // Auto-search if post_id is pre-populated.
    $(function () {
        if ($('#asae-pw-activity-post-id').val()) {
            searchActivityLog(1);
        }
    });

    /* =====================================================================
       Activity Log: Post search autocomplete
       ===================================================================== */

    var searchTimer = null;

    $(document).on('input', '#asae-pw-activity-post-search', function () {
        var query = $(this).val();
        var $results = $('#asae-pw-post-search-results');

        clearTimeout(searchTimer);

        if (query.length < 2) {
            $results.attr('hidden', 'hidden');
            $('#asae-pw-activity-post-id').val('');
            return;
        }

        searchTimer = setTimeout(function () {
            $.post(asaePW.ajaxUrl, {
                action: 'asae_pw_search_posts',
                nonce: asaePW.nonces.activity,
                search: query
            }, function (response) {
                if (response.success && response.data.posts.length) {
                    var html = '';
                    $.each(response.data.posts, function (i, post) {
                        html += '<div class="asae-pw-autocomplete-item" tabindex="0" data-id="' + post.id + '">' + escHtml(post.title) + '</div>';
                    });
                    $results.html(html).removeAttr('hidden');
                } else {
                    $results.attr('hidden', 'hidden');
                }
            });
        }, 300);
    });

    $(document).on('click keypress', '.asae-pw-autocomplete-item', function (e) {
        if (e.type === 'keypress' && e.key !== 'Enter') return;
        $('#asae-pw-activity-post-search').val($(this).text());
        $('#asae-pw-activity-post-id').val($(this).data('id'));
        $('#asae-pw-post-search-results').attr('hidden', 'hidden');
    });

    // Hide autocomplete on blur.
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#asae-pw-post-search-results, #asae-pw-activity-post-search').length) {
            $('#asae-pw-post-search-results').attr('hidden', 'hidden');
        }
    });

    /* =====================================================================
       Activity Log: CSV Export
       ===================================================================== */

    $(document).on('click', '#asae-pw-export-csv', function () {
        var $form = $('#asae-pw-activity-filter-form');

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_export_activity_csv',
            nonce: asaePW.nonces.activity,
            post_id: $form.find('[name="post_id"]').val(),
            user_id: $form.find('[name="user_id"]').val(),
            action_type: $form.find('[name="action_type"]').val(),
            term_id: $form.find('[name="term_id"]').val(),
            date_from: $form.find('[name="date_from"]').val(),
            date_to: $form.find('[name="date_to"]').val()
        }, function (response) {
            if (!response.success) {
                alert(response.data.message || asaePW.i18n.error);
                return;
            }

            var csv = response.data.csv;
            var csvContent = csv.map(function (row) {
                return row.map(function (cell) {
                    return '"' + String(cell).replace(/"/g, '""') + '"';
                }).join(',');
            }).join('\n');

            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'activity-log-' + new Date().toISOString().slice(0, 10) + '.csv';
            link.click();
        });
    });

    /* =====================================================================
       Assignments: Add
       ===================================================================== */

    $(document).on('submit', '#asae-pw-add-assignment-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $spinner = $form.find('.spinner');

        var termIds = [];
        $form.find('input[name="term_ids[]"]:checked').each(function () {
            termIds.push($(this).val());
        });

        $spinner.addClass('is-active');

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_add_assignment',
            nonce: asaePW.nonces.assignments,
            user_id: $form.find('[name="user_id"]').val(),
            role: $form.find('[name="role"]').val(),
            term_ids: termIds
        }, function (response) {
            $spinner.removeClass('is-active');
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
            alert(asaePW.i18n.error);
        });
    });

    /* =====================================================================
       Assignments: Delete
       ===================================================================== */

    $(document).on('click', '.asae-pw-delete-assignment', function () {
        if (!confirm('Remove this assignment?')) return;

        var id = $(this).data('id');
        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_delete_assignment',
            nonce: asaePW.nonces.assignments,
            assignment_id: id
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
            }
        });
    });

    $(document).on('click', '.asae-pw-bulk-delete-assignments', function () {
        var ids = [];
        $('input[name="assignment_ids[]"]:checked').each(function () {
            ids.push($(this).val());
        });

        if (!ids.length) {
            alert('No assignments selected.');
            return;
        }

        if (!confirm('Remove ' + ids.length + ' assignment(s)?')) return;

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_bulk_delete_assignments',
            nonce: asaePW.nonces.assignments,
            assignment_ids: ids
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || asaePW.i18n.error);
            }
        });
    });

    // Select All checkbox.
    $(document).on('change', '#cb-select-all', function () {
        $('input[name="assignment_ids[]"]').prop('checked', $(this).is(':checked'));
    });

    /* =====================================================================
       Settings: Save
       ===================================================================== */

    $(document).on('submit', '#asae-pw-settings-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $spinner = $form.find('.spinner');

        $spinner.addClass('is-active');

        var postTypes = [];
        $form.find('input[name="post_types[]"]:checked').each(function () {
            postTypes.push($(this).val());
        });

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_save_settings',
            nonce: asaePW.nonces.settings,
            disable_xmlrpc: $form.find('[name="disable_xmlrpc"]').is(':checked') ? 1 : 0,
            notification_sender_name: $form.find('[name="notification_sender_name"]').val(),
            notification_sender_email: $form.find('[name="notification_sender_email"]').val(),
            post_types: postTypes,
            orphaned_content: $form.find('[name="orphaned_content"]:checked').val(),
            delete_terms_on_uninstall: $form.find('[name="delete_terms_on_uninstall"]').is(':checked') ? 1 : 0
        }, function (response) {
            $spinner.removeClass('is-active');
            if (response.success) {
                // Show WP admin notice.
                var $notice = $('<div class="notice notice-success is-dismissible"><p>' + escHtml(response.data.message) + '</p></div>');
                $form.before($notice);
                setTimeout(function () { $notice.fadeOut(); }, 3000);
            } else {
                alert(response.data.message || asaePW.i18n.error);
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
            alert(asaePW.i18n.error);
        });
    });

    /* =====================================================================
       Settings: Check for Updates
       ===================================================================== */

    $(document).on('click', '#asae-pw-check-updates', function () {
        var $btn = $(this);
        var $spinner = $('#asae-pw-update-spinner');
        var $result = $('#asae-pw-update-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.text('');

        $.post(asaePW.ajaxUrl, {
            action: 'asae_pw_check_updates',
            nonce: asaePW.nonces.settings
        }, function (response) {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
            $result.text(response.data.message || '');
        }).fail(function () {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
            $result.text(asaePW.i18n.error);
        });
    });

    /* =====================================================================
       Utility: HTML escape
       ===================================================================== */

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
