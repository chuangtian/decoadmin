"use strict";

(() => {
  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = String(text);
    return node;
  };
  const safeAction = (value) => {
    try {
      const url = new URL(value, location.origin);
      return url.origin === location.origin && ["http:", "https:"].includes(url.protocol) ? url.href : "";
    } catch {
      return "";
    }
  };
  const render = (root, payload) => {
    const form = root.querySelector("[data-dr-organic-form]");
    const config = payload?.form;
    const enabled = Boolean(config && payload.settings?.organic_collection_enabled && root.dataset.showForm !== "false"
      && root.dataset.mode === "reviews" && Number(root.dataset.productId || 0) > 0);
    if (!form || !enabled) {
      if (form) form.hidden = true;
      return;
    }
    const action = safeAction(root.dataset.formAction || form.action);
    if (!action) {
      form.hidden = true;
      return;
    }
    form.action = action;
    form.hidden = false;
    root.dataset.allowPhotos = config.allow_photos ? "true" : "false";
    root.dataset.allowVideo = config.allow_video ? "true" : "false";
    const write = (selector, value) => {
      const node = form.querySelector(selector);
      if (node && value) node.textContent = String(value);
    };
    write("[data-dr-form-heading]", config.heading);
    write("[data-dr-form-description]", config.description);
    write("[data-dr-name-label]", config.name_label);
    write("[data-dr-title-label]", config.title_label);
    write("[data-dr-body-label]", config.body_label);
    write("[data-dr-submit-label]", config.submit_label);
    const version = form.querySelector("[data-dr-form-version]");
    if (version) version.value = config.version || "initial";
    const mediaField = form.querySelector("[data-dr-media-field]");
    const mediaHelp = form.querySelector("[data-dr-media-help]");
    const mediaInput = mediaField?.querySelector("input");
    const mediaEnabled = Boolean(config.allow_photos || config.allow_video);
    if (mediaField) mediaField.hidden = !mediaEnabled;
    if (mediaHelp) mediaHelp.hidden = !mediaEnabled;
    if (mediaInput) mediaInput.accept = [config.allow_photos ? "image/*" : "", config.allow_video ? "video/*" : ""].filter(Boolean).join(",");
    const questions = form.querySelector("[data-dr-questions]");
    if (!questions) return;
    questions.replaceChildren();
    (Array.isArray(config.questions) ? config.questions : []).forEach((question) => {
      if (!question?.id || !question.label || !["single", "multiple", "scale"].includes(question.type)) return;
      const fieldset = element("fieldset", "dr-question");
      if (question.type === "multiple") fieldset.setAttribute("data-answer-multiple", "");
      if (question.required) fieldset.setAttribute("data-required", "");
      fieldset.append(element("legend", "", question.label));
      if (question.type === "scale") {
        const select = element("select", "dr-select");
        select.name = `answers[${question.id}]`;
        select.required = Boolean(question.required);
        const blank = element("option", "", "Choose"); blank.value = ""; select.append(blank);
        for (let value = Number(question.min); value <= Number(question.max); value += 1) {
          const option = element("option", "", value); option.value = String(value); select.append(option);
        }
        fieldset.append(select);
      } else {
        const options = element("div", "dr-question__options");
        (Array.isArray(question.options) ? question.options : []).forEach((value, index) => {
          const input = element("input");
          input.type = question.type === "multiple" ? "checkbox" : "radio";
          input.name = `answers[${question.id}]${question.type === "multiple" ? "[]" : ""}`;
          input.value = String(value);
          if (question.type === "single" && question.required && index === 0) input.required = true;
          const option = element("label"); option.append(input, document.createTextNode(` ${value}`)); options.append(option);
        });
        fieldset.append(options);
      }
      fieldset.append(element("small", "dr-question__visibility", question.public
        ? "This answer may be shown publicly." : "Only the store can see this answer."));
      questions.append(fieldset);
    });
  };
  window.DecoReviewsOrganic = { render };
  document.querySelectorAll("[data-deco-reviews]").forEach((root) => {
    if (root.decoReviewsPayload) render(root, root.decoReviewsPayload);
  });
})();
