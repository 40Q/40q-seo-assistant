(function (wp, settings) {
  if (!wp) {
    return;
  }

  settings = settings || {};

  const { registerPlugin } = wp.plugins || {};
  const { PluginSidebar, PluginSidebarMoreMenuItem } = (wp.editPost || {});
  if (!registerPlugin || !PluginSidebar || !PluginSidebarMoreMenuItem) {
    return;
  }
  const { Button, Modal, Notice, PanelBody, Spinner, TextControl, TextareaControl, ToggleControl } = wp.components;
  const { createElement: el, Fragment, useEffect, useMemo, useState } = wp.element;
  const { __ } = wp.i18n;
  const { select } = wp.data;
  const apiFetch = wp.apiFetch;

  const restNamespace = (settings.restNamespace || '/40q-seo-assistant/v1').replace(/\/+$/, '');
  const hasTSF = !!settings.hasTSF;

  if (settings.nonce && apiFetch?.createNonceMiddleware) {
    apiFetch.use(apiFetch.createNonceMiddleware(settings.nonce));
  }

  const initialSuggestion = {
    meta_title: '',
    meta_description: '',
    open_graph_title: '',
    open_graph_description: '',
    twitter_title: '',
    twitter_description: '',
    keywords: [],
  };

  const resolvePostId = () => settings.postId || select('core/editor')?.getCurrentPostId?.() || 0;
  const storeKey = '__fortyqSeoAssistantStore';

  function getStore() {
    if (!window[storeKey]) {
      window[storeKey] = {};
    }
    return window[storeKey];
  }

  function loadCached(postId) {
    if (!postId) return null;
    const store = getStore();
    return store[postId] || null;
  }

  function saveCached(postId, data) {
    if (!postId) return;
    const store = getStore();
    store[postId] = data;
  }

  function clearCached(postId) {
    if (!postId) return;
    const store = getStore();
    delete store[postId];
  }

  const sections = [
    {
      key: 'meta',
      label: __('Meta', 'radicle'),
      fields: [
        { key: 'meta_title', label: __('Title', 'radicle'), control: 'text', max: 60, help: __('Also used as default Open Graph and Twitter title', 'radicle') },
        { key: 'meta_description', label: __('Description', 'radicle'), control: 'textarea', max: 180 },
      ],
    },
    {
      key: 'og',
      label: __('Open Graph', 'radicle'),
      fields: [
        { key: 'open_graph_title', label: __('Title', 'radicle'), control: 'text', max: 60 },
        { key: 'open_graph_description', label: __('Description', 'radicle'), control: 'textarea', max: 200 },
      ],
    },
    {
      key: 'twitter',
      label: __('Twitter', 'radicle'),
      fields: [
        { key: 'twitter_title', label: __('Title', 'radicle'), control: 'text', max: 60 },
        { key: 'twitter_description', label: __('Description', 'radicle'), control: 'textarea', max: 200 },
      ],
    },
  ];

  const SuggestionFields = ({ suggestions, setSuggestions, currentMeta, applyFlags, setApplyFlags }) =>
    el(
      Fragment,
      null,
      ...sections.map((section) =>
        el(
          'div',
          {
            key: section.key,
            style: {
              border: '1px solid #dedede',
              borderRadius: '8px',
              padding: '12px',
              marginBottom: '12px',
            },
          },
          el(
            'div',
            { style: { display: 'grid', gridTemplateColumns: '1fr 2fr', gap: '12px', alignItems: 'start' } },
            ...section.fields.map((field, idx) => {
              const current = currentMeta?.[field.key] || '';
              const help = current ? `${__('Current', 'radicle')}: ${current}` : __('Current: (empty)', 'radicle');
              const onToggle = (checked) => setApplyFlags((prev) => ({ ...prev, [field.key]: checked }));
              const onChange = (value) => setSuggestions({ ...suggestions, [field.key]: value });

              return el(
                'div',
                { key: field.key, style: { border: '1px solid #e7e7e7', borderRadius: '6px', padding: '10px' } },
                el(
                  'div',
                  { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' } },
                  el('strong', null, `${section.label} ${field.label}`),
                  el(ToggleControl, {
                    label: __('Apply', 'radicle'),
                    checked: !!applyFlags[field.key],
                    onChange: onToggle,
                  })
                ),
                field.control === 'text'
                  ? el(TextControl, {
                    label: __('Suggested', 'radicle'),
                    value: suggestions[field.key] || '',
                    maxLength: field.max,
                    onChange,
                    help,
                  })
                  : el(TextareaControl, {
                    label: __('Suggested', 'radicle'),
                    value: suggestions[field.key] || '',
                    maxLength: field.max,
                    onChange,
                    help,
                    rows: 2,
                  })
              );
            })
          )
        )
      ),
      suggestions?.keywords?.length
        ? el(
            'p',
            { style: { marginTop: '8px', fontSize: '12px', color: '#50575e' } },
            __('Detected keywords:', 'radicle'),
            ' ',
            suggestions.keywords.join(', ')
          )
        : null
    );

  const Sidebar = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [isApplying, setIsApplying] = useState(false);
    const [hasSuggestions, setHasSuggestions] = useState(false);
    const [hasFetched, setHasFetched] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [suggestions, setSuggestions] = useState(initialSuggestion);
    const [currentMeta, setCurrentMeta] = useState({});
    const [applyFlags, setApplyFlags] = useState(buildDefaultApplyFlags());

    const postId = useMemo(resolvePostId, []);

    useEffect(() => {
      setHasFetched(false);
      const stored = loadCached(postId);
      if (stored) {
        setSuggestions({ ...initialSuggestion, ...(stored.suggestions || {}) });
        setCurrentMeta(stored.currentMeta || {});
        setApplyFlags(buildDefaultApplyFlags(stored.suggestions));
        setHasSuggestions(true);
        setHasFetched(true);
      }
    }, [postId]);

    const fetchSuggestions = async (force = false) => {
      if (hasFetched && !force) {
        setError('');
        setNotice('');
        setIsModalOpen(true);
        return;
      }

      const cached = !force ? loadCached(postId) : null;

      // If we already have cached suggestions and not forcing, reuse without new request.
      if (cached) {
        setSuggestions({ ...initialSuggestion, ...(cached.suggestions || {}) });
        setCurrentMeta(cached.currentMeta || {});
        setApplyFlags(buildDefaultApplyFlags(cached.suggestions));
        setHasSuggestions(true);
        setHasFetched(true);
        setIsModalOpen(true);
        return;
      }

      setError('');
      setNotice('');
      setIsLoading(true);

      try {
        const payload = {
          post_id: postId,
          content: select('core/editor')?.getEditedPostContent?.() || '',
          title: select('core/editor')?.getEditedPostAttribute?.('title') || '',
          raw_blocks: JSON.stringify(select('core/block-editor')?.getBlocks?.() || []),
        };

        const response = await apiFetch({
          path: `${restNamespace}/suggest`,
          method: 'POST',
          data: payload,
        });

        setSuggestions({ ...initialSuggestion, ...(response?.suggestions || {}) });
        setCurrentMeta(response?.current_meta || {});
        setApplyFlags(buildDefaultApplyFlags(response?.suggestions));
        setHasSuggestions(true);
        setHasFetched(true);
        saveCached(postId, {
          suggestions: response?.suggestions || {},
          currentMeta: response?.current_meta || {},
        });
        setIsModalOpen(true);
      } catch (err) {
        setError(err?.message || __('Unable to generate suggestions.', 'radicle'));
      } finally {
        setIsLoading(false);
      }
    };

    const applySuggestions = async () => {
      setError('');
      setNotice('');
      setIsApplying(true);

      try {
        const response = await apiFetch({
          path: `${restNamespace}/apply`,
          method: 'POST',
          data: {
            post_id: postId,
            apply: applyFlags,
            meta_title: suggestions.meta_title,
            meta_description: suggestions.meta_description,
            open_graph_title: suggestions.open_graph_title || suggestions.meta_title,
            open_graph_description: suggestions.open_graph_description || suggestions.meta_description,
            twitter_title: suggestions.twitter_title || suggestions.meta_title,
            twitter_description: suggestions.twitter_description || suggestions.meta_description,
          },
        });

        // Optimistically mirror into TSF fields so the metabox shows the changes without reload.
        updateTsfFields({
          meta_title: suggestions.meta_title,
          meta_description: suggestions.meta_description,
          open_graph_title: suggestions.open_graph_title || suggestions.meta_title,
          open_graph_description: suggestions.open_graph_description || suggestions.meta_description,
          twitter_title: suggestions.twitter_title || suggestions.meta_title,
          twitter_description: suggestions.twitter_description || suggestions.meta_description,
          apply: applyFlags,
        });

        setNotice(
          response?.success
            ? __('SEO Framework fields updated.', 'radicle')
            : __('No changes were applied.', 'radicle')
        );
        setIsModalOpen(false);
      } catch (err) {
        setError(err?.message || __('Unable to apply suggestions.', 'radicle'));
      } finally {
        setIsApplying(false);
      }
    };

    useEffect(() => {
      if (!postId) {
        setError(__('Post ID missing. Save the draft before requesting suggestions.', 'radicle'));
      }
    }, [postId]);

    const sidebarContent = el(
      PanelBody,
      { title: __('SEO Assistant (The SEO Framework)', 'radicle'), initialOpen: true },
      error
        ? el(Notice, { status: 'error', isDismissible: false }, error)
        : null,
      notice
        ? el(Notice, { status: 'success', isDismissible: true, onRemove: () => setNotice('') }, notice)
        : null,
      el(
        Button,
        {
          variant: 'primary',
          disabled: isLoading || !postId || !hasTSF,
          onClick: () => fetchSuggestions(),
        },
        isLoading ? el(Spinner, null) : __('Suggest metadata', 'radicle')
      ),
      !hasTSF
        ? el(
            Notice,
            { status: 'warning', isDismissible: false, style: { marginTop: '8px' } },
            __('The SEO Framework is required for the assistant to run. Activate it to enable suggestions.', 'radicle')
          )
        : null,
      el(
        'p',
        { style: { marginTop: '8px', fontSize: '12px', color: '#50575e' } },
        __('Analyzes the current content and suggests titles and descriptions. You can review and apply them to The SEO Framework.', 'radicle')
      )
    );

    const modalContent =
      isModalOpen &&
      el(
        Modal,
        {
          title: __('Suggested metadata', 'radicle'),
          onRequestClose: () => setIsModalOpen(false),
        },
        error ? el(Notice, { status: 'error', isDismissible: false }, error) : null,
        el(SuggestionFields, { suggestions, setSuggestions, currentMeta, applyFlags, setApplyFlags }),
        el(
          'div',
          { style: { display: 'flex', justifyContent: 'flex-end', gap: '8px', marginTop: '12px' } },
          el(
            Button,
            { variant: 'secondary', onClick: () => setIsModalOpen(false) },
            __('Cancel', 'radicle')
          ),
          el(
            Button,
            {
              variant: 'secondary',
              disabled: isLoading,
              onClick: () => {
                clearCached(postId);
                fetchSuggestions(true);
              },
            },
            isLoading ? el(Spinner, null) : __('Refresh suggestions', 'radicle')
          ),
          el(
            Button,
            { variant: 'primary', isBusy: isApplying, disabled: isApplying, onClick: applySuggestions },
            isApplying ? el(Spinner, null) : __('Apply to The SEO Framework', 'radicle')
          )
        )
      );

    return el(
      Fragment,
      null,
      el(PluginSidebarMoreMenuItem, { target: 'fortyq-seo-assistant-sidebar', icon: 'lightbulb' }, __('SEO Assistant', 'radicle')),
      el(
        PluginSidebar,
        {
          name: 'fortyq-seo-assistant-sidebar',
          title: __('SEO Assistant', 'radicle'),
          icon: 'lightbulb',
        },
        sidebarContent
      ),
      modalContent
    );
  };

  /**
   * Update The SEO Framework meta box fields in-place so users see changes immediately.
   */
  function updateTsfFields(fields) {
    const map = {
      meta_title: '#autodescription_title',
      meta_description: '#autodescription_description',
      open_graph_title: '#autodescription_og_title',
      open_graph_description: '#autodescription_og_description',
      twitter_title: '#autodescription_twitter_title',
      twitter_description: '#autodescription_twitter_description',
    };

    Object.entries(map).forEach(([key, selector]) => {
      const el = document.querySelector(selector);
      if (!el) return;
      const shouldApply = fields.apply ? !!fields.apply[key] : true;
      if (!shouldApply) return;
      const value = fields[key];
      if (typeof value !== 'string') return;
      el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  function buildDefaultApplyFlags(suggestions = initialSuggestion) {
    return {
      meta_title: !!(suggestions?.meta_title ?? true),
      meta_description: !!(suggestions?.meta_description ?? true),
      open_graph_title: !!(suggestions?.open_graph_title ?? true),
      open_graph_description: !!(suggestions?.open_graph_description ?? true),
      twitter_title: !!(suggestions?.twitter_title ?? true),
      twitter_description: !!(suggestions?.twitter_description ?? true),
    };
  }

  registerPlugin('fortyq-seo-assistant', { icon: 'lightbulb', render: Sidebar });
})(window.wp, window.seoAssistantSettings || {});
