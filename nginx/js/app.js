import {htmlToElement} from "./util.js";

export default class App extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: "open" });
    }

    async connectedCallback() {
        await this.init();

        this.addEventListener("next_question", this.handleNextQuestion);
    }

    async handleNextQuestion() {
        this.shadowRoot.innerHTML = '';
        await this.init();
    }

    async init() {
        let params = (new URL(document.location)).searchParams;
        let group_id = params.get('group_id');
        let token = params.get('token');
        params = new URLSearchParams({group_id, token});
        let response = await fetch(`/app?${params.toString()}`);
        if (response.status === 200) {
            let { question } = await response.json();
            this.shadowRoot.append(htmlToElement(`
                <app-prompt 
                    question="${question}"
                    group="${group_id}"
                    token="${token}"
                />`));
        } else {
            let { error } = await response.json();
            this.shadowRoot.append(htmlToElement(`<app-error message="${error}"></app-error>`));
        }
    }
}